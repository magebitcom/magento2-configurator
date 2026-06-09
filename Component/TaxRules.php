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
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
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
class TaxRules implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'taxrules';
    private const DESCRIPTION = 'Component to create Tax Rules';

    /**
     * Column order of the source CSV (header row). Must match docs/schema/taxrules.md.
     */
    private const COLUMNS = [
        'code',
        'tax_rate_ids',
        'customer_tax_class_ids',
        'product_tax_class_ids',
        'priority',
        'calculate_subtotal',
        'position',
    ];

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

    /**
     * Export tax rules into the source CSV format (list of rows, header first).
     * Refresh mode rebuilds only the rules already tracked in the source file
     * (matched by `code`), rewriting each from its current DB state; a tracked
     * rule no longer present in the DB keeps its existing row unchanged. Full mode
     * dumps every tax rule, optionally filtered by a `code` prefix.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->getFilter());
    }

    /**
     * Rebuild the rows whose `code` is already tracked in the source file, reading
     * current values from the DB. The header row is preserved (or rebuilt). Tracked
     * rules with no matching DB rule keep their existing row.
     *
     * @param array $existing
     * @param string|null $filter
     * @return array
     */
    private function refreshTracked(array $existing, ?string $filter): array
    {
        if (!isset($existing[0]) || !is_array($existing[0])) {
            return $existing;
        }

        $header = $existing[0];
        $rows = [$header];

        foreach ($existing as $index => $row) {
            if ($index === 0 || !is_array($row)) {
                continue;
            }

            $code = (string) ($row[0] ?? '');
            if ($code === '' || ($filter !== null && $filter !== '' && !str_starts_with($code, $filter))) {
                $rows[] = $row;
                continue;
            }

            $rule = $this->loadRuleByCode($code);
            if ($rule === null) {
                // Tracked rule no longer exists in the DB: keep its existing row.
                $rows[] = $row;
                continue;
            }

            $rows[] = $this->ruleToRow($rule, $header);
        }

        return $rows;
    }

    /**
     * Dump every tax rule as source rows, header first. When a `code` prefix
     * filter is given, only matching rules are exported.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $rows = [self::COLUMNS];

        $collection = $this->ruleFactory->create()->getCollection();
        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter('code', ['like' => $filter . '%']);
        }

        foreach ($collection as $item) {
            $rule = $this->loadRuleByCode((string) $item->getCode());
            if ($rule === null) {
                continue;
            }
            $rows[] = $this->ruleToRow($rule, self::COLUMNS);
        }

        return $rows;
    }

    /**
     * Load a fully-populated rule by code (its tax rate / class id sets are filled
     * on load), or null when no rule with that code exists.
     */
    private function loadRuleByCode(string $code): ?object
    {
        $id = $this->ruleFactory->create()->getCollection()
            ->addFieldToFilter('code', $code)
            ->getFirstItem()
            ->getId();

        if (!$id) {
            return null;
        }

        $rule = $this->ruleFactory->create();
        $this->taxRuleResource->load($rule, (int) $id);

        return $rule->getId() ? $rule : null;
    }

    /**
     * Build a CSV row for a rule, ordered to match the given header columns. Rate
     * ids are resolved back to rate codes and tax-class ids back to class names.
     *
     * @param object $rule
     * @param array $header
     * @return array
     */
    private function ruleToRow(object $rule, array $header): array
    {
        $values = [
            'code' => (string) $rule->getCode(),
            'tax_rate_ids' => $this->rateCodesFromIds((array) $rule->getTaxRateIds()),
            'customer_tax_class_ids' => $this->classNamesFromIds((array) $rule->getCustomerTaxClassIds()),
            'product_tax_class_ids' => $this->classNamesFromIds((array) $rule->getProductTaxClassIds()),
            'priority' => (string) $rule->getPriority(),
            'calculate_subtotal' => (string) $rule->getCalculateSubtotal(),
            'position' => (string) $rule->getPosition(),
        ];

        $row = [];
        foreach ($header as $column) {
            $row[] = $values[$column] ?? '';
        }

        return $row;
    }

    /**
     * Resolve tax rate ids back to a comma-separated list of rate codes.
     *
     * @param array $rateIds
     * @return string
     */
    private function rateCodesFromIds(array $rateIds): string
    {
        $codes = [];
        foreach ($rateIds as $rateId) {
            $rate = $this->rateFactory->create();
            $rate->load((int) $rateId);
            if ($rate->getId()) {
                $codes[] = (string) $rate->getCode();
            }
        }

        return implode(',', $codes);
    }

    /**
     * Resolve tax-class ids back to a comma-separated list of class names.
     *
     * @param array $classIds
     * @return string
     */
    private function classNamesFromIds(array $classIds): string
    {
        $names = [];
        foreach ($classIds as $classId) {
            $class = $this->classModelFactory->create();
            $this->taxClassResource->load($class, (int) $classId);
            if ($class->getId()) {
                $names[] = (string) $class->getClassName();
            }
        }

        return implode(',', $names);
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
