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
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\SalesSequence\Model\Builder;
use Magento\SalesSequence\Model\EntityPool;
use Magento\SalesSequence\Model\Config;
use Magento\Store\Api\StoreRepositoryInterface;
use Magebit\Configurator\Api\LoggerInterface;

class Sequence implements ComponentInterface
{
    private const ALIAS = 'sequence';
    private const DESCRIPTION = 'Component to allow manual configuring of the sequence tables.';

    public function __construct(
        private readonly Builder $sequenceBuilder,
        private readonly EntityPool $entityPool,
        private readonly Config $sequenceConfig,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['stores']) || !is_array($data['stores'])) {
            $result->addError('No "stores" node found in the source data.');
            return $result;
        }

        foreach ($data['stores'] as $code => $overrides) {
            try {
                $this->logger->logInfo(__("Starting creating sequence tables for %1", $code));
                $store = $this->storeRepository->get($code);
                $this->newSequenceTable($store, $overrides, $context->isDryRun(), $result);
                $this->logger->logInfo(__("Finished creating sequence tables for %1", $code));
                // todo handle existing sequence tables
            } catch (\Exception $exception) {
                $this->logger->logError($exception->getMessage());
                $result->addError($exception->getMessage());
            }
        }

        return $result;
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }

    protected function newSequenceTable($store, $overrides, bool $dryRun, ComponentResult $result): void
    {
        $configKeys = ['suffix', 'startValue', 'step', 'warningValue', 'maxValue'];
        $configValues = [];
        foreach ($configKeys as $key) {
            $configValues[$key] = $this->sequenceConfig->get($key);
            if (isset($overrides[$key])) {
                $configValues[$key] = $overrides[$key];
            }
        }

        // Prefix Value
        $configValues['prefix'] = $store->getId();
        if (isset($overrides['prefix'])) {
            $configValues['prefix'] = $overrides['prefix'];
        }

        foreach ($this->entityPool->getEntities() as $entityType) {
            try {
                $this->logger->logComment(__(
                    'Store: %1 '.
                    'Prefix: %2, '.
                    'Suffix: %3, '.
                    'Start Value: %4, '.
                    'Step: %5, '.
                    'Warning Value: %6, '.
                    'Max Value: %7, '.
                    'Entity Type: %8',
                    $store->getCode(),
                    $configValues['prefix'],
                    $configValues['suffix'],
                    $configValues['startValue'],
                    $configValues['step'],
                    $configValues['warningValue'],
                    $configValues['maxValue'],
                    $entityType
                ), 1);

                if ($dryRun) {
                    $this->logger->logInfo(
                        __("[dry-run] Would create sequence table for %1", $entityType),
                        1
                    );
                    $result->recordCreated();
                    continue;
                }

                $this->sequenceBuilder->setPrefix($configValues['prefix'])
                    ->setSuffix($configValues['suffix'])
                    ->setStartValue($configValues['startValue'])
                    ->setStoreId($store->getId())
                    ->setStep($configValues['step'])
                    ->setWarningValue($configValues['warningValue'])
                    ->setMaxValue($configValues['maxValue'])
                    ->setEntityType($entityType)
                    ->create();
                $this->logger->logInfo(__("Sequence table created for %1", $entityType), 1);
                $result->recordCreated();
            } catch (\Exception $exception) {
                $this->logger->logError($exception->getMessage());
                $result->addError($exception->getMessage());
            }
        }
    }
}
