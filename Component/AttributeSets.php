<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\Data\AttributeSetInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class AttributeSets implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'attribute_sets';
    private const DESCRIPTION = 'Component to create/maintain attribute sets.';

    public function __construct(
        private readonly EavSetup $eavSetup,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate,
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $attributeConfigurationData = $context->getData();

        if (!isset($attributeConfigurationData['attribute_sets'])
            || !is_array($attributeConfigurationData['attribute_sets'])
        ) {
            $result->addError('No "attribute_sets" node found in the source data.');
            return $result;
        }

        try {
            foreach ($attributeConfigurationData['attribute_sets'] as $attributeSetConfiguration) {
                $this->processAttributeSet(
                    $attributeSetConfiguration,
                    $context->getMode(),
                    $context->isDryRun(),
                    $result
                );
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    protected function processAttributeSet(
        array $attributeSetConfig,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $name = $attributeSetConfig['name'];
        // getAttributeSetId() throws when the set is missing, so probe with getAttributeSet().
        $attributeSetData = $this->eavSetup->getAttributeSet(Product::ENTITY, $name);
        $existingId = is_array($attributeSetData) ? ($attributeSetData['attribute_set_id'] ?? null) : null;
        $exists = !empty($existingId);

        // Explicit removal: `remove: true` deletes the set if it exists, in either
        // mode. Idempotent — a set already absent is skipped. Checked before the
        // gate and any required-field handling so it is always honored.
        if (!empty($attributeSetConfig['remove'])) {
            $this->removeAttributeSet((string) $name, $exists ? (int) $existingId : false, $dryRun, $result);
            return;
        }

        $version = $attributeSetConfig['version'] ?? null;
        $request = new ReconciliationRequest(
            self::ALIAS,
            (string) $name,
            $mode,
            $exists,
            $version ? (int) $version : null
        );

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logComment(sprintf('Attribute set "%s" exists, skipped (create mode)', $name));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would %s attribute set: "%s"', $outcome->value, $name));
            $outcome->record($result);
            return;
        }

        $isCreate = $outcome === ReconciliationOutcome::Create;

        if ($isCreate) {
            $this->eavSetup->addAttributeSet(Product::ENTITY, $name);
            $this->log->logInfo(sprintf('Creating attribute set: "%s"', $name));
            $existingId = $this->eavSetup->getAttributeSetId(Product::ENTITY, $name);
        } else {
            $this->log->logInfo(sprintf('Reconciling attribute set: "%s"', $name));
        }

        $attributeSetEntity = $this->attributeSetRepository->get($existingId);

        // initFromSkeleton resets the set from a template; only safe on creation,
        // re-running it on an existing set would wipe its current groups/attributes.
        if ($isCreate && array_key_exists('inherit', $attributeSetConfig)) {
            $attributeSetEntity->initFromSkeleton($this->getAttributeSetId($attributeSetConfig['inherit']));
            $this->attributeSetRepository->save($attributeSetEntity);
        }

        // Group association is idempotent (it checks existence), so it runs in both modes.
        if (array_key_exists('groups', $attributeSetConfig) && count($attributeSetConfig['groups']) > 0) {
            $this->addAttributeGroups($attributeSetEntity, $attributeSetConfig['groups']);
        }

        $this->gate->commitVersion($request, $dryRun);
        $outcome->record($result);
    }

    /**
     * Delete an attribute set flagged with `remove: true`. Idempotent: a set that
     * is already absent records a skip rather than an error. Honors dry-run. The
     * entity-type default attribute set is never deleted — it would orphan every
     * product still assigned to it.
     *
     * @param string $name
     * @param false|int $attributeSetId
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     */
    protected function removeAttributeSet(
        string $name,
        false|int $attributeSetId,
        bool $dryRun,
        ComponentResult $result
    ): void {
        if (!$attributeSetId) {
            $this->log->logComment(sprintf("Attribute set '%s' not present, nothing to remove", $name));
            $result->recordSkipped();
            return;
        }

        $defaultSetId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getDefaultAttributeSetId();
        if ($attributeSetId === $defaultSetId) {
            $this->log->logComment(
                sprintf("Attribute set '%s' is the default set and cannot be removed, skipped", $name)
            );
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove attribute set %s', $name));
        } else {
            $this->attributeSetRepository->deleteById($attributeSetId);
            $this->log->logInfo(sprintf('Removed attribute set %s', $name));
        }

        $result->recordRemoved();
    }

    protected function addAttributeGroups(AttributeSetInterface $attributeSetEntity, array $attributeGroupData): void
    {
        $attributeSetName = $attributeSetEntity->getAttributeSetName();

        // Loop through the groups that belong to the attribute set
        foreach ($attributeGroupData as $group) {
            try {
                // Used to predetermine the code if not using a custom attribute group code
                if (!isset($group['code'])) {
                    $group['code'] = $this->eavSetup->convertToAttributeGroupCode($group['name']);
                }

                // Check if the attribute group exist
                $attributeGroup = $this->eavSetup->getAttributeGroup(
                    Product::ENTITY,
                    $attributeSetName,
                    $group['code'],
                    'attribute_set_id'
                );

                // If not then create the group
                if (!$attributeGroup) {
                    $this->eavSetup->addAttributeGroup(Product::ENTITY, $attributeSetName, $group['name']);
                    $this->log->logInfo(sprintf('Creating group: "%s"', $group['name']), 1);
                }

                if ($attributeGroup) {
                    $this->log->logComment(sprintf('Existing group: "%s"', $group['name']), 1);
                }

                // Attempt to associate the attributes to the group
                $this->addAttributeGroupAssociations($attributeSetEntity, $group);
            } catch (\Zend_Db_Statement_Exception $exception) {
                $this->log->logError(
                    'Magento sometimes uses different attribute codes to attribute names. '
                    .'You may require to specify the code too.',
                    1
                );
                $this->log->logError(
                    sprintf('Attribute Set: %s, Group: %s', $attributeSetName, $group['name']),
                    1
                );
                $this->log->logError($exception->getMessage(), 1);
            }
        }
    }

    protected function addAttributeGroupAssociations(
        AttributeSetInterface $attributeSetEntity,
        array $group
    ): void {
        foreach ($group['attributes'] as $attributeCode) {
            $attributeData = $this->eavSetup->getAttribute(Product::ENTITY, $attributeCode);

            if (count($attributeData) === 0) {
                throw new ComponentException((string) __("Attribute '%1' does not exist.", $attributeCode));
            }

            $this->eavSetup->addAttributeToGroup(
                Product::ENTITY,
                $attributeSetEntity->getId(),
                $group['name'],
                $attributeCode
            );

            $this->log->logInfo(sprintf('Adding attribute "%s"', $attributeCode), 2);
        }
    }

    protected function getAttributeSetId($attributeSetName): string
    {
        $attributeSetData = $this->eavSetup->getAttributeSet(Product::ENTITY, $attributeSetName);
        if (array_key_exists('attribute_set_id', $attributeSetData)) {
            return $attributeSetData['attribute_set_id'];
        }

        throw new ComponentException((string) __('Could not find attribute set name.'));
    }

    /**
     * Export attribute sets with their groups and the USER-DEFINED attributes
     * assigned to each group (system/inherited attributes are skipped — they come
     * from the skeleton and would be huge, non-portable noise). Refresh re-exports
     * the tracked sets (preserving `inherit`, which cannot be reversed, and
     * `version`); full mode exports every set (optional name-prefix filter).
     */
    public function export(ExportContext $context): array
    {
        $sets = $this->fetchSets($context->getFilter());

        if ($context->isFullExport()) {
            $out = [];
            foreach ($sets as $setId => $setName) {
                $out[] = $this->buildSetEntry($setId, $setName, []);
            }
            return ['attribute_sets' => $out];
        }

        $existing = $context->getExistingData();
        $tracked = (isset($existing['attribute_sets']) && is_array($existing['attribute_sets']))
            ? $existing['attribute_sets']
            : [];
        $idByName = array_flip($sets);

        $out = [];
        foreach ($tracked as $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                $out[] = $entry;
                continue;
            }
            $name = (string) $entry['name'];
            if (!isset($idByName[$name])) {
                // Set no longer exists; keep the tracked entry untouched.
                $out[] = $entry;
                continue;
            }
            $out[] = $this->buildSetEntry((int) $idByName[$name], $name, $entry);
        }

        return ['attribute_sets' => $out];
    }

    /**
     * @return array<int, string> attribute_set_id => name, for the product entity.
     */
    private function fetchSets(?string $filter): array
    {
        $entityTypeId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getId();
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('eav_attribute_set'),
                ['attribute_set_id', 'attribute_set_name']
            )
            ->where('entity_type_id = ?', $entityTypeId)
            ->order('attribute_set_name ASC');
        if ($filter !== null && $filter !== '') {
            $select->where('attribute_set_name LIKE ?', $filter . '%');
        }

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['attribute_set_id']] = (string) $row['attribute_set_name'];
        }

        return $out;
    }

    /**
     * @param array $preserve Tracked entry whose non-DB keys (inherit, version) are kept.
     * @return array
     */
    private function buildSetEntry(int $setId, string $setName, array $preserve): array
    {
        $entry = ['name' => $setName];
        if (isset($preserve['inherit'])) {
            $entry['inherit'] = $preserve['inherit'];
        }
        if (array_key_exists('version', $preserve)) {
            $entry['version'] = $preserve['version'];
        }

        $groups = [];
        foreach ($this->fetchGroups($setId) as $group) {
            $attributes = $this->fetchUserDefinedAttributes((int) $group['attribute_group_id']);
            if ($attributes === []) {
                continue;
            }
            $groupEntry = ['name' => (string) $group['attribute_group_name']];
            if (!empty($group['attribute_group_code'])) {
                $groupEntry['code'] = (string) $group['attribute_group_code'];
            }
            $groupEntry['attributes'] = $attributes;
            $groups[] = $groupEntry;
        }
        if ($groups !== []) {
            $entry['groups'] = $groups;
        }

        return $entry;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchGroups(int $setId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('eav_attribute_group'),
                ['attribute_group_id', 'attribute_group_name', 'attribute_group_code']
            )
            ->where('attribute_set_id = ?', $setId)
            ->order('sort_order ASC');

        return $connection->fetchAll($select);
    }

    /**
     * @return string[] user-defined attribute codes assigned to the group, in order.
     */
    private function fetchUserDefinedAttributes(int $groupId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['eea' => $this->resourceConnection->getTableName('eav_entity_attribute')], [])
            ->join(
                ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                'ea.attribute_id = eea.attribute_id',
                ['attribute_code']
            )
            ->where('eea.attribute_group_id = ?', $groupId)
            ->where('ea.is_user_defined = ?', 1)
            ->order('eea.sort_order ASC');

        return array_map('strval', $connection->fetchCol($select));
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
