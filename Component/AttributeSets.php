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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
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
        private readonly LoggerInterface $log
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
                $this->processAttributeSet($attributeSetConfiguration, $context->isDryRun(), $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    protected function processAttributeSet(array $attributeSetConfig, bool $dryRun, ComponentResult $result): void
    {
        if ($dryRun) {
            $this->log->logInfo(
                sprintf('[dry-run] Would create attribute set: "%s"', $attributeSetConfig['name'])
            );
            $result->recordCreated();
            return;
        }

        $this->eavSetup->addAttributeSet(Product::ENTITY, $attributeSetConfig['name']);

        $this->log->logInfo(sprintf('Creating attribute set: "%s"', $attributeSetConfig['name']));
        $result->recordCreated();

        $attributeSetId = $this->eavSetup->getAttributeSetId(Product::ENTITY, $attributeSetConfig['name']);
        $attributeSetEntity = $this->attributeSetRepository->get($attributeSetId);
        if (array_key_exists('inherit', $attributeSetConfig)) {
            $attributeSetEntity->initFromSkeleton($this->getAttributeSetId($attributeSetConfig['inherit']));
            $this->attributeSetRepository->save($attributeSetEntity);
        }

        if (array_key_exists('groups', $attributeSetConfig) && count($attributeSetConfig['groups']) > 0) {
            $this->addAttributeGroups($attributeSetEntity, $attributeSetConfig['groups']);
        }
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
