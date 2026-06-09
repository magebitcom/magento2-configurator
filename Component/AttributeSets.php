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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\Data\AttributeSetInterface;
use Magento\Eav\Setup\EavSetup;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class AttributeSets implements ComponentInterface
{
    private const ALIAS = 'attribute_sets';
    private const DESCRIPTION = 'Component to create/maintain attribute sets.';

    public function __construct(
        private readonly EavSetup $eavSetup,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
