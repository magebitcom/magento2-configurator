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
use Magebit\Configurator\Component\CatalogPriceRules\CatalogPriceRulesProcessor;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magento\CatalogRule\Api\Data\RuleInterfaceFactory;
use Magento\CatalogRule\Model\Rule;

/**
 * Manages Catalog Price Rules by delegating to the CatalogPriceRulesProcessor.
 */
class CatalogPriceRules implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'catalog_price_rules';
    private const DESCRIPTION = 'Component to manage Catalog Price Rules';

    /**
     * Rule fields written into the source format, in the order the schema lists
     * them. `name` is handled separately as the identity key.
     */
    private const EXPORT_FIELDS = [
        'description',
        'is_active',
        'sort_order',
        'website_ids',
        'customer_group_ids',
        'from_date',
        'to_date',
        'conditions_serialized',
        'actions_serialized',
        'simple_action',
        'discount_amount',
        'stop_rules_processing',
    ];

    public function __construct(
        private readonly CatalogPriceRulesProcessor $processor,
        private readonly LoggerInterface $log,
        private readonly RuleInterfaceFactory $ruleFactory
    ) {
    }

    /**
     * Process the data and populate the Magento database.
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['rules']) || !is_array($data['rules'])) {
            $result->addError('No "rules" node found in the source data.');
            return $result;
        }

        $rules = $data['rules'];
        $config = $data['config'] ?? [];

        $ruleCount = count($rules);

        if ($context->isDryRun()) {
            $this->log->logInfo(
                sprintf('[dry-run] Would process %d Catalog Price Rule(s)', $ruleCount)
            );
            $result->recordCreated($ruleCount);

            return $result;
        }

        $this->processor->setData($rules)
            ->setConfig($config)
            ->setMode($context->getMode())
            ->process();

        $result->recordCreated($ruleCount);

        return $result;
    }

    /**
     * Export current catalog price rules into the source format. Refresh mode
     * rewrites only the rules already tracked in the source file (matched by
     * `name`), rebuilding their fields from the DB while preserving non-value
     * keys (e.g. version); a tracked rule no longer in the DB is kept untouched.
     * Full mode dumps every catalog price rule (optionally filtered by a
     * name prefix).
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->getFilter());
    }

    /**
     * @param array $existing
     * @param string|null $filter
     * @return array
     */
    private function refreshTracked(array $existing, ?string $filter): array
    {
        $out = $existing;
        $rules = $existing['rules'] ?? null;

        if (!is_array($rules)) {
            return $out;
        }

        $refreshed = [];
        foreach ($rules as $key => $rule) {
            if (!is_array($rule) || !isset($rule['name'])) {
                // Not a recognisable tracked entry; keep it unchanged.
                $refreshed[$key] = $rule;
                continue;
            }

            $name = (string) $rule['name'];
            if ($filter !== null && $filter !== '' && !str_starts_with($name, $filter)) {
                $refreshed[$key] = $rule;
                continue;
            }

            $model = $this->findRuleByName($name);
            if ($model === null) {
                // Tracked rule no longer exists in the DB: keep the entry as-is.
                $refreshed[$key] = $rule;
                continue;
            }

            // Rebuild value fields from the DB, preserving non-value keys
            // (name, version, and any extra keys already in the entry).
            $current = $this->ruleToSource($model);
            foreach (self::EXPORT_FIELDS as $field) {
                if (array_key_exists($field, $current)) {
                    $rule[$field] = $current[$field];
                }
            }
            $refreshed[$key] = $rule;
        }

        $out['rules'] = $refreshed;

        return $out;
    }

    /**
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $collection = $this->ruleFactory->create()->getCollection();

        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter('name', ['like' => $filter . '%']);
        }

        $rules = [];
        $index = 1;
        foreach ($collection as $rule) {
            /** @var Rule $rule */
            $key = 'rule' . $index++;
            $rules[$key] = ['name' => (string) $rule->getName()] + $this->ruleToSource($rule);
        }

        return ['rules' => $rules];
    }

    /**
     * Load a single catalog price rule by name, or null when there is not
     * exactly one match (matching the create/update behaviour in execute()).
     */
    private function findRuleByName(string $name): ?Rule
    {
        $collection = $this->ruleFactory->create()->getCollection()
            ->addFieldToFilter('name', $name);

        if ($collection->getSize() !== 1) {
            return null;
        }

        /** @var Rule $rule */
        $rule = $collection->getFirstItem();

        return $rule->getId() ? $rule : null;
    }

    /**
     * Map a catalog price rule model onto the documented source fields.
     *
     * @return array<string, mixed>
     */
    private function ruleToSource(Rule $rule): array
    {
        return [
            'description' => (string) $rule->getData('description'),
            'is_active' => (int) $rule->getData('is_active'),
            'sort_order' => (int) $rule->getData('sort_order'),
            'website_ids' => $this->toIntList($rule->getWebsiteIds()),
            'customer_group_ids' => $this->toIntList($rule->getCustomerGroupIds()),
            'from_date' => $this->toSourceDate($rule->getData('from_date')),
            'to_date' => $this->toSourceDate($rule->getData('to_date')),
            'conditions_serialized' => (string) $rule->getData('conditions_serialized'),
            'actions_serialized' => (string) $rule->getData('actions_serialized'),
            'simple_action' => (string) $rule->getData('simple_action'),
            'discount_amount' => $rule->getData('discount_amount'),
            'stop_rules_processing' => (int) $rule->getData('stop_rules_processing'),
        ];
    }

    /**
     * Normalise a website/customer-group id set into a plain list of ints.
     *
     * @param mixed $ids
     * @return int[]
     */
    private function toIntList(mixed $ids): array
    {
        if (is_string($ids)) {
            $ids = $ids === '' ? [] : explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    /**
     * Convert a stored date (`Y-m-d`) back into the source `d/m/Y` format.
     * Blank/null dates round-trip as null so they stay empty in the file.
     *
     * @param mixed $date
     */
    private function toSourceDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $timestamp = strtotime((string) $date);
        if ($timestamp === false) {
            return (string) $date;
        }

        return date('j/n/Y', $timestamp);
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
