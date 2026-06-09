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
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Tax\Model\Calculation\RuleFactory;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\ClassModelFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rule as TaxRuleResource;
use Magento\Tax\Model\ResourceModel\TaxClass as TaxClassResource;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class TaxRules implements ComponentInterface
{
    private const ALIAS = 'taxrules';
    private const DESCRIPTION = 'Component to create Tax Rules';

    /**
     * Defines Customer Tax Class string
     */
    public const TAX_CLASS_TYPE_CUSTOMER = 'CUSTOMER';

    /**
     * Defines Product Tax Class string
     */
    public const TAX_CLASS_TYPE_PRODUCT = 'PRODUCT';

    public function __construct(
        private readonly RateFactory $rateFactory,
        private readonly ClassModelFactory $classModelFactory,
        private readonly RuleFactory $ruleFactory,
        private readonly TaxRuleResource $taxRuleResource,
        private readonly TaxClassResource $taxClassResource,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        //Check Row Data exists
        if (!isset($data[0])) {
            $result->addError('No row data found.');
            return $result;
        }

        $taxRuleAttributes = $this->getAttributesFromCsv($data[0]);
        unset($data[0]);

        foreach ($data as $rule) {
            if (!isset($rule['0']) || $rule[0] == '') {
                $this->log->logError(
                    sprintf('Tax Rule creation skipped: Code is a required field')
                );
                $result->recordSkipped();

                continue;
            }

            $ruleData = $this->formatArray($taxRuleAttributes, $rule, $context->isDryRun());

            try {
                $this->createTaxRule($ruleData, $context, $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        $this->log->logComment(
            sprintf('Tax Rules import finished')
        );

        return $result;
    }

    /**
     * Gets the first row of the CSV file as these should be the attribute keys
     *
     * @param array $data
     * @return array
     */
    public function getAttributesFromCsv(array $data): array
    {
        $attributes = [];
        foreach ($data as $attributeCode) {
            $attributes[] = $attributeCode;
        }
        return $attributes;
    }

    /**
     * Assign array values to useable keys names for rule creation
     *
     * @param array $taxRuleAttributes
     * @param array $rule
     * @return array
     */
    private function formatArray(array $taxRuleAttributes, array $rule, bool $dryRun): array
    {
        $ruleData = [];

        //Set Keys
        foreach ($taxRuleAttributes as $column => $code) {
            $ruleData[$code] = $rule[$column];
        }

        //Ensure default values are passed
        foreach ($ruleData as $key => $value) {
            if (!isset($value)) {
                $ruleData[$key] = 0;
            }
        }

        $ruleData['tax_rate_ids'] = $this->getRateIdsFromCode($ruleData['tax_rate_ids']);

        $ruleData['customer_tax_class_ids'] = $this->taxClassIdsFromName(
            self::TAX_CLASS_TYPE_CUSTOMER,
            $ruleData['customer_tax_class_ids'],
            $dryRun
        );

        $ruleData['product_tax_class_ids'] = $this->taxClassIdsFromName(
            self::TAX_CLASS_TYPE_PRODUCT,
            $ruleData['product_tax_class_ids'],
            $dryRun
        );

        return $ruleData;
    }

    /**
     * Use Rate code to get Rate ID
     *
     * @param string $rateNames
     * @return array
     */
    private function getRateIdsFromCode(string $rateNames): array
    {
        $rateIds = [];
        $rateNamesArray = explode(',', $rateNames);

        foreach ($rateNamesArray as $name) {
            $rateFactory = $this->rateFactory->create()->getCollection();
            $rate = $rateFactory->addFieldToFilter('code', $name)->load()->getFirstItem();
            $rateIds[] = $rate->getId();
        }

        return $rateIds;
    }

    /**
     * Use TaxClass name to get TaxClass Id
     *
     * @param string $type
     * @param string $names
     * @return array
     */
    private function taxClassIdsFromName(string $type, string $names, bool $dryRun): array
    {
        $taxClassIds = [];
        $taxClassNamesArray = explode(',', $names);
        $classModel = $this->classModelFactory->create();
        $classCollection = $classModel->getCollection();

        foreach ($taxClassNamesArray as $name) {
            $class = $classCollection->addFieldToFilter('class_name', $name)->getFirstItem();
            $classId = $class->getId();

            if ($classId == 0) {
                if ($dryRun) {
                    // Don't create the missing tax class during a dry run; the rule
                    // that depends on it won't be created either.
                    $this->log->logInfo(sprintf('[dry-run] Would create missing tax class "%s"', $name));
                    continue;
                }

                $classModel->setClassName($name)
                    ->setClassType($type);
                $this->taxClassResource->save($classModel);
                $classId = $classModel->getId();
            }

            $taxClassIds[] = $classId;
        }

        return $taxClassIds;
    }

    /**
     * Create TaxRule
     *
     * @param array $ruleData
     * @param ComponentContext $context
     * @param ComponentResult $result
     */
    private function createTaxRule(array $ruleData, ComponentContext $context, ComponentResult $result): void
    {
        $rule = $this->ruleFactory->create();
        $existing = $rule->getCollection()->addFieldToFilter('code', $ruleData['code'])->getFirstItem();
        $exists = (bool) $existing->getId();

        $request = new ReconciliationRequest(self::ALIAS, (string) $ruleData['code'], $context->getMode(), $exists);

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logComment(
                sprintf('Tax Rule "%s" exists, skipped (create mode).', $ruleData['code'])
            );
            $result->recordSkipped();

            return;
        }

        if ($context->isDryRun()) {
            $this->log->logInfo(
                sprintf('[dry-run] Would %s Tax Rule "%s".', $outcome->value, $ruleData['code'])
            );
            $outcome->record($result);

            return;
        }

        $rule = $outcome === ReconciliationOutcome::Create ? $rule : $existing;
        $rule->setCode($ruleData['code'])
            ->setTaxRateIds($ruleData['tax_rate_ids'])
            ->setCustomerTaxClassIds($ruleData['customer_tax_class_ids'])
            ->setProductTaxClassIds($ruleData['product_tax_class_ids'])
            ->setPriority($ruleData['priority'])
            ->setCalculateSubtotal($ruleData['calculate_subtotal'])
            ->setPosition($ruleData['position']);
        $this->taxRuleResource->save($rule);

        $this->log->logInfo(
            sprintf('Tax Rule "%s" %s.', $ruleData['code'], $outcome->value)
        );
        $outcome->record($result);
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
