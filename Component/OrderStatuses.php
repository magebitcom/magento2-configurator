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
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;

/**
 * Class OrderStatuses
 * @package Magebit\Configurator\Component
 */
class OrderStatuses implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'order_statuses';
    private const DESCRIPTION = 'Component to create custom order statuses';

    public function __construct(
        private readonly StatusFactory $statusFactory,
        private readonly StatusResourceFactory $statusResourceFactory,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['order_statuses']) || !is_array($data['order_statuses'])) {
            $result->addError('No "order_statuses" node found in the source data.');
            return $result;
        }

        foreach ($data['order_statuses'] as $statusSet) {
            try {
                $this->createOrderStatuses($statusSet, $context->getMode(), $context->isDryRun(), $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @param array $statusSet
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function createOrderStatuses(
        array $statusSet,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        foreach ($statusSet['statuses'] as $statusData) {
            $code = $statusData['code'];

            /** @var StatusResource $statusResource */
            $statusResource = $this->statusResourceFactory->create();
            /** @var Status $status */
            $status = $this->statusFactory->create();
            $statusResource->load($status, $code);
            $exists = (bool) $status->getStatus();

            $version = $statusData['version'] ?? null;
            $request = new ReconciliationRequest(
                self::ALIAS,
                (string) $code,
                $mode,
                $exists,
                $version ? (int) $version : null
            );

            $outcome = $this->gate->decide($request);
            if ($outcome->isSkip()) {
                $this->log->logComment(sprintf('Order status %s exists, skipped (create mode)', $statusData['name']));
                $result->recordSkipped();
                continue;
            }

            if ($dryRun) {
                $this->log->logInfo(
                    sprintf('[dry-run] Would %s order status %s', $outcome->value, $statusData['name'])
                );
                $outcome->record($result);
                continue;
            }

            $status->setData('status', $code);
            $status->setData('label', $statusData['name']);

            try {
                $statusResource->save($status);
                $status->assignState($statusSet['state'], false, true);
            } catch (\Exception $e) {
                $this->log->logError($e->getMessage());
            }

            $this->gate->commitVersion($request, $dryRun);
            $this->log->logInfo(
                sprintf('Order status %s %s', $statusData['name'], $outcome->value)
            );
            $outcome->record($result);
        }
    }

    /**
     * Export current order statuses into the source format. Refresh mode rewrites
     * only the status codes already tracked in the source file (refreshing their
     * name from the DB and preserving state grouping and non-value keys such as
     * version); full mode dumps every status grouped by its assigned state,
     * optionally limited to codes that start with the given filter.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild every order status in the DB in the source format, grouped by the
     * state each status is assigned to. Statuses with no state assignment are
     * skipped, since the source format requires a parent state. Optionally limited
     * to statuses whose code starts with the given filter.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $byState = [];

        foreach ($this->loadStatusMap() as $code => $info) {
            if ($filter !== null && $filter !== '' && !str_starts_with($code, $filter)) {
                continue;
            }
            $state = $info['state'];
            if ($state === null || $state === '') {
                // The source format groups statuses under a state; skip unassigned.
                continue;
            }

            $byState[$state][] = ['code' => $code, 'name' => $info['name']];
        }

        $sets = [];
        foreach ($byState as $state => $statuses) {
            $sets[] = ['state' => $state, 'statuses' => $statuses];
        }

        return ['order_statuses' => $sets];
    }

    /**
     * Walk the tracked source structure and refresh each tracked status's name
     * from the DB, preserving state grouping and any non-value keys (version).
     * Tracked statuses whose record no longer exists in the DB are kept untouched.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        if (!isset($existing['order_statuses']) || !is_array($existing['order_statuses'])) {
            return $existing;
        }

        $statusMap = $this->loadStatusMap();
        $out = $existing;

        foreach ($existing['order_statuses'] as $setIndex => $statusSet) {
            if (!is_array($statusSet) || !isset($statusSet['statuses']) || !is_array($statusSet['statuses'])) {
                continue;
            }

            foreach ($statusSet['statuses'] as $statusIndex => $statusEntry) {
                if (!is_array($statusEntry) || !isset($statusEntry['code'])) {
                    continue;
                }

                $code = (string) $statusEntry['code'];
                if (!isset($statusMap[$code])) {
                    // Record no longer exists in the DB; keep the tracked entry as-is.
                    continue;
                }

                $statusEntry['name'] = $statusMap[$code]['name'];
                $out['order_statuses'][$setIndex]['statuses'][$statusIndex] = $statusEntry;
            }
        }

        return $out;
    }

    /**
     * Load every order status keyed by its code, with its label and the state it
     * is assigned to (null when unassigned), read from the live DB.
     *
     * @return array<string, array{name: string, state: string|null}>
     */
    private function loadStatusMap(): array
    {
        /** @var Status $status */
        $status = $this->statusFactory->create();
        $collection = $status->getResourceCollection();
        $collection->joinStates();

        $map = [];
        foreach ($collection as $item) {
            $code = (string) $item->getStatus();
            $map[$code] = [
                'name' => (string) $item->getLabel(),
                'state' => $item->getState() !== null ? (string) $item->getState() : null,
            ];
        }

        return $map;
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
