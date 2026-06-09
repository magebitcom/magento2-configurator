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
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Tax\Api\TaxClassRepositoryInterface;

/**
 * Creates customer groups, each linked to a tax class, from configurator YAML.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class CustomerGroups implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'customergroups';
    private const DESCRIPTION = 'Component to create customer groups.';

    /** Magento's customer_group_code column is limited to this many characters. */
    private const MAX_GROUP_NAME_LENGTH = 32;

    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly GroupInterfaceFactory $groupFactory,
        private readonly TaxClassRepositoryInterface $taxClassRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['customergroups']) || !is_array($data['customergroups'])) {
            $result->addError('No "customergroups" node found in the source data.');
            return $result;
        }

        foreach ($data['customergroups'] as $taxClassConfig) {
            $taxClassName = $taxClassConfig['taxclass'] ?? null;
            if ($taxClassName === null) {
                $result->addError('A "customergroups" entry is missing the required "taxclass" key.');
                continue;
            }

            $taxClassId = $this->getTaxClassIdByName((string) $taxClassName, $result);
            if ($taxClassId === null) {
                continue;
            }

            foreach ($taxClassConfig['groups'] ?? [] as $group) {
                try {
                    // Explicit removal: `remove: true` deletes the group if it exists,
                    // in either mode. Checked before required-field validation so a
                    // removal entry never trips it. Idempotent — absent groups skip.
                    if (!empty($group['remove'])) {
                        $this->removeCustomerGroup($this->extractGroupName($group), $context->isDryRun(), $result);
                        continue;
                    }

                    $this->createCustomerGroup($this->extractGroupName($group), $taxClassId, $context, $result);
                } catch (ComponentException $e) {
                    $this->log->logError($e->getMessage());
                    $result->addError($e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Create a customer group, or in maintain mode re-point its tax class.
     */
    private function createCustomerGroup(
        string $groupName,
        int $taxClassId,
        ComponentContext $context,
        ComponentResult $result
    ): void {
        $existing = $this->findGroup($groupName);
        $exists = $existing !== null;
        $unchanged = $exists && (int) $existing->getTaxClassId() === $taxClassId;

        $request = new ReconciliationRequest(
            self::ALIAS,
            $groupName,
            $context->getMode(),
            $exists,
            null,
            $exists ? $unchanged : null
        );

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logInfo(sprintf(
                'Customer Group "%s" %s, skipped',
                $groupName,
                $unchanged ? 'unchanged' : 'protected (create mode)'
            ));
            $result->recordSkipped();
            return;
        }

        if ($context->isDryRun()) {
            $this->log->logInfo(sprintf('[dry-run] Would %s Customer Group "%s"', $outcome->value, $groupName));
            $outcome->record($result);
            return;
        }

        $group = $exists ? $existing : $this->groupFactory->create();
        $group->setCode($groupName);
        $group->setTaxClassId($taxClassId);
        $this->groupRepository->save($group);

        $this->log->logInfo(sprintf(
            'Customer Group "%s" %s',
            $groupName,
            $outcome === ReconciliationOutcome::Create ? 'created' : 'updated'
        ));
        $outcome->record($result);
    }

    /**
     * Delete a customer group flagged with `remove: true`. Idempotent: a group that
     * is already absent records a skip rather than an error. Honors dry-run.
     */
    private function removeCustomerGroup(string $groupName, bool $dryRun, ComponentResult $result): void
    {
        $existing = $this->findGroup($groupName);
        if ($existing === null) {
            $this->log->logComment(sprintf("Customer Group '%s' not present, nothing to remove", $groupName));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove Customer Group %s', $groupName));
        } else {
            $this->groupRepository->deleteById((int) $existing->getId());
            $this->log->logInfo(sprintf('Removed Customer Group %s', $groupName));
        }

        $result->recordRemoved();
    }

    private function findGroup(string $groupName): ?GroupInterface
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(GroupInterface::CODE, $groupName)
            ->create();

        $items = $this->groupRepository->getList($criteria)->getItems();

        return $items === [] ? null : current($items);
    }

    /**
     * Validate and return the group name from a single group config entry.
     *
     * @param array $group
     * @throws ComponentException
     */
    private function extractGroupName(array $group): string
    {
        if (!isset($group['name']) || $group['name'] === '') {
            throw new ComponentException((string) __('The customer group name is mandatory'));
        }

        $name = (string) $group['name'];
        if (strlen($name) > self::MAX_GROUP_NAME_LENGTH) {
            throw new ComponentException((string) __(
                'The customer group name "%1" is too long (maximum length is %2 characters)',
                $name,
                self::MAX_GROUP_NAME_LENGTH
            ));
        }

        return $name;
    }

    /**
     * Resolve a tax class id from its name, or null (logged + recorded) when missing.
     */
    private function getTaxClassIdByName(string $taxClassName, ComponentResult $result): ?int
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('class_name', $taxClassName)
            ->create();

        $taxClasses = $this->taxClassRepository->getList($criteria)->getItems();
        $taxClass = $taxClasses === [] ? null : current($taxClasses);

        if ($taxClass === null) {
            $message = sprintf('There is no Tax class with the name "%s" in this database', $taxClassName);
            $this->log->logError($message);
            $result->addError($message);
            return null;
        }

        return (int) $taxClass->getClassId();
    }

    /**
     * Export customer groups into the source format (tax class name -> groups[].name).
     * Refresh mode rewrites only the groups already tracked in the source file, placing
     * each under its current tax class; full mode dumps every customer group in the
     * database (optionally filtered by a group-code prefix).
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild the source structure for the groups already tracked in the file, reading
     * each group's current tax class from the DB. Tracked groups that no longer exist
     * in the DB keep their original entry under their original tax class. Non-value keys
     * on a tax-class entry (e.g. version) are preserved.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        $tracked = $existing['customergroups'] ?? null;
        if (!is_array($tracked)) {
            return $existing;
        }

        $buckets = [];
        $order = [];
        $taxClassNameCache = [];

        foreach ($tracked as $taxClassConfig) {
            $originalTaxClass = $taxClassConfig['taxclass'] ?? null;
            $this->rememberTaxClass($buckets, $order, $taxClassConfig, (string) $originalTaxClass);

            foreach ($taxClassConfig['groups'] ?? [] as $group) {
                $groupName = is_array($group) ? ($group['name'] ?? null) : null;
                if ($groupName === null || $groupName === '') {
                    continue;
                }

                $existingGroup = $this->findGroup((string) $groupName);
                if ($existingGroup === null) {
                    // Tracked group gone from the DB: keep it under its original tax class.
                    $buckets[(string) $originalTaxClass]['groups'][] = $group;
                    continue;
                }

                $currentTaxClass = $this->getTaxClassNameById((int) $existingGroup->getTaxClassId(), $taxClassNameCache)
                    ?? (string) $originalTaxClass;
                $this->rememberTaxClass($buckets, $order, [], $currentTaxClass);
                $buckets[$currentTaxClass]['groups'][] = ['name' => (string) $existingGroup->getCode()];
            }
        }

        $out = [];
        foreach ($order as $taxClassName) {
            $out[] = $buckets[$taxClassName];
        }

        return ['customergroups' => $out];
    }

    /**
     * Export every customer group in the database, grouped by tax class name. When a
     * filter is set, only groups whose code starts with it are exported.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $criteria = $this->searchCriteriaBuilder->create();
        $groups = $this->groupRepository->getList($criteria)->getItems();

        $buckets = [];
        $order = [];
        $taxClassNameCache = [];

        foreach ($groups as $group) {
            $code = (string) $group->getCode();
            if ($filter !== null && $filter !== '' && !str_starts_with($code, $filter)) {
                continue;
            }

            $taxClassName = $this->getTaxClassNameById((int) $group->getTaxClassId(), $taxClassNameCache);
            if ($taxClassName === null) {
                continue;
            }

            $this->rememberTaxClass($buckets, $order, [], $taxClassName);
            $buckets[$taxClassName]['groups'][] = ['name' => $code];
        }

        $out = [];
        foreach ($order as $taxClassName) {
            $out[] = $buckets[$taxClassName];
        }

        return ['customergroups' => $out];
    }

    /**
     * Ensure a tax-class bucket exists, preserving any non-value keys (taxclass,
     * version, …) from the source entry the first time it is seen.
     *
     * @param array<string, array> $buckets
     * @param array<int, string> $order
     * @param array $sourceEntry
     */
    private function rememberTaxClass(array &$buckets, array &$order, array $sourceEntry, string $taxClassName): void
    {
        if (isset($buckets[$taxClassName])) {
            return;
        }

        $bucket = $sourceEntry;
        unset($bucket['groups']);
        $bucket['taxclass'] = $taxClassName;
        $bucket['groups'] = [];

        $buckets[$taxClassName] = $bucket;
        $order[] = $taxClassName;
    }

    /**
     * Resolve a tax class name from its id, caching lookups. Returns null if the tax
     * class no longer exists.
     *
     * @param array<int, string|null> $cache
     */
    private function getTaxClassNameById(int $taxClassId, array &$cache): ?string
    {
        if (array_key_exists($taxClassId, $cache)) {
            return $cache[$taxClassId];
        }

        try {
            $cache[$taxClassId] = (string) $this->taxClassRepository->get($taxClassId)->getClassName();
        } catch (NoSuchEntityException $e) {
            $cache[$taxClassId] = null;
        }

        return $cache[$taxClassId];
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
