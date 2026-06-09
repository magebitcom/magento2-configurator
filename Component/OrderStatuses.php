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
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
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
class OrderStatuses implements ComponentInterface
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
