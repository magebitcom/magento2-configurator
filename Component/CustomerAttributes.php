<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Customer\Model\Customer;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Eav\Model\AttributeRepository;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Model\ResourceModel\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as AttrOptionCollectionFactory;
use Magento\Swatches\Helper\Data as SwatchHelper;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class CustomerAttributes extends Attributes
{
    public const DEFAULT_ATTRIBUTE_SET_ID = 1;
    public const DEFAULT_ATTRIBUTE_GROUP_ID = 1;

    private const ALIAS = 'customer_attributes';
    private const DESCRIPTION = 'Component to create/maintain customer attributes.';

    /**
     * @var string
     */
    protected $entityTypeId = Customer::ENTITY;

    protected $customerConfigMap = [
        'visible' => 'is_visible',
        'position' => 'sort_order',
        'system' => 'is_system'
    ];

    /**
     * @var array
     */
    protected $defaultForms = [
        'values' => [
            'customer_account_create',
            'customer_account_edit',
            'adminhtml_checkout',
            'adminhtml_customer'
        ]
    ];

    public function __construct(
        EavSetup $eavSetup,
        AttributeRepository $attributeRepository,
        private readonly CustomerSetupFactory $customerSetup,
        private readonly Attribute $attributeResource,
        LoggerInterface $log,
        AttrOptionCollectionFactory $attrOptionCollectionFactory,
        EavConfig $eavConfig,
        ReconciliationGate $gate,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SwatchHelper $swatchHelper
    ) {
        parent::__construct(
            $eavSetup,
            $attributeRepository,
            $log,
            $attrOptionCollectionFactory,
            $eavConfig,
            $gate,
            $searchCriteriaBuilder,
            $swatchHelper
        );
        $this->attributeConfigMap = array_merge($this->attributeConfigMap, $this->customerConfigMap);
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['customer_attributes']) || !is_array($data['customer_attributes'])) {
            $result->addError('No "customer_attributes" node found in the source data.');
            return $result;
        }

        try {
            foreach ($data['customer_attributes'] as $attributeCode => $attributeConfiguration) {
                // Explicit removal: `remove: true` deletes the attribute in either
                // mode (bypassing the reconciliation gate). Determined before any
                // create/update or required-field handling. Idempotent and dry-run aware.
                if (!empty($attributeConfiguration['remove'])) {
                    $this->removeCustomerAttribute(
                        (string) $attributeCode,
                        $context->isDryRun(),
                        $result
                    );
                    continue;
                }

                $this->processAttribute(
                    $attributeCode,
                    $attributeConfiguration,
                    $context->getMode(),
                    $context->isDryRun(),
                    $result
                );
                $this->addAdditionalValues($attributeCode, $attributeConfiguration, $context->isDryRun());
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * Adds necessary additional values to the attribute. Without these, values can't be saved
     * to the attribute and it won't appear in any forms.
     *
     * @param string $attributeCode
     * @param array $attributeConfiguration
     */
    protected function addAdditionalValues($attributeCode, $attributeConfiguration, bool $dryRun): void
    {
        if ($this->attributeExists) {
            return;
        }
        if (!isset($attributeConfiguration['used_in_forms']) ||
            !isset($attributeConfiguration['used_in_forms']['values'])) {
            $attributeConfiguration['used_in_forms'] = $this->defaultForms;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would apply additional values to %s.', $attributeCode));
            return;
        }

        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetup->create();
        try {
            $attribute = $customerSetup->getEavConfig()
                ->getAttribute($this->entityTypeId, $attributeCode)
                ->addData([
                    'attribute_set_id' => self::DEFAULT_ATTRIBUTE_SET_ID,
                    'attribute_group_id' => self::DEFAULT_ATTRIBUTE_GROUP_ID,
                    'used_in_forms' => $attributeConfiguration['used_in_forms']['values']
                ]);
            $this->attributeResource->save($attribute);
        } catch (LocalizedException $e) {
            $this->log->logError(sprintf(
                'Error applying additional values to %s: %s',
                $attributeCode,
                $e->getMessage()
            ));
        } catch (\Exception $e) {
            $this->log->logError(sprintf(
                'Error saving additional values for %s: %s',
                $attributeCode,
                $e->getMessage()
            ));
        }
    }

    /**
     * Delete a customer attribute flagged with `remove: true`. Idempotent: an
     * attribute that is absent (or is not user-defined) records a skip rather than
     * an error. Honors dry-run. Removal applies in both create and maintain mode.
     *
     * @param string $attributeCode
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     */
    protected function removeCustomerAttribute(string $attributeCode, bool $dryRun, ComponentResult $result): void
    {
        $attributeArray = $this->eavSetup->getAttribute($this->entityTypeId, $attributeCode);

        // Only user-defined attributes may be removed; system attributes are left alone.
        if (!$attributeArray || empty($attributeArray['attribute_id']) || empty($attributeArray['is_user_defined'])) {
            $this->log->logComment(sprintf(
                "Customer attribute '%s' not present (or not user-defined), nothing to remove",
                $attributeCode
            ));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove customer attribute %s', $attributeCode));
        } else {
            $this->eavSetup->removeAttribute($this->entityTypeId, $attributeCode);
            $this->log->logInfo(sprintf('Removed customer attribute %s', $attributeCode));
        }

        $result->recordRemoved();
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
