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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterfaceFactory;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventoryApi\Api\StockSourceLinksSaveInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;

/**
 * Creates/maintains the Multi-Source-Inventory topology: sources, stocks,
 * their source-stock links and sales-channel (website) assignments. Run this
 * before the products component so its `msi_sources` source items have
 * somewhere to land. MSI is part of Magento Open Source 2.4.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class InventorySources implements ComponentInterface
{
    private const ALIAS = 'inventory_sources';
    private const DESCRIPTION = 'Component to create/maintain MSI sources, stocks and their links.';

    public function __construct(
        private readonly SourceInterfaceFactory $sourceFactory,
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly StockInterfaceFactory $stockFactory,
        private readonly StockRepositoryInterface $stockRepository,
        private readonly StockSourceLinkInterfaceFactory $linkFactory,
        private readonly StockSourceLinksSaveInterface $linksSave,
        private readonly SalesChannelInterfaceFactory $salesChannelFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!is_array($data) || $data === []) {
            $result->addError('No "inventory_sources" data found in the source data.');
            return $result;
        }

        // Sources first, so stock source-links can reference them.
        foreach ($data['sources'] ?? [] as $code => $sourceData) {
            try {
                $this->processSource((string) $code, (array) $sourceData, $context, $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        foreach ($data['stocks'] ?? [] as $name => $stockData) {
            try {
                $this->processStock((string) $name, (array) $stockData, $context, $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @throws ComponentException
     */
    private function processSource(string $code, array $data, ComponentContext $context, ComponentResult $result): void
    {
        $existing = $this->findSource($code);
        $exists = $existing !== null;

        $version = $data['version'] ?? null;
        $request = new ReconciliationRequest(
            self::ALIAS,
            'source_' . $code,
            $context->getMode(),
            $exists,
            $version ? (int) $version : null
        );

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logComment(sprintf('MSI source "%s" exists, skipped (create mode).', $code));
            $result->recordSkipped();
            return;
        }

        if ($context->isDryRun()) {
            $this->log->logInfo(sprintf('[dry-run] Would %s MSI source "%s".', $outcome->value, $code));
            $outcome->record($result);
            return;
        }

        $source = $exists ? $existing : $this->sourceFactory->create();
        $source->setSourceCode($code);
        foreach ($data as $key => $value) {
            if ($key === 'version') {
                continue;
            }
            $source->setData($key, $value);
        }

        try {
            $this->sourceRepository->save($source);
        } catch (\Exception $e) {
            throw new ComponentException((string) __('Failed to save MSI source "%1": %2', $code, $e->getMessage()));
        }

        $this->log->logInfo(sprintf('MSI source "%s" %s.', $code, $outcome->value));
        $this->gate->commitVersion($request, $context->isDryRun());
        $outcome->record($result);
    }

    /**
     * @throws ComponentException
     */
    private function processStock(string $name, array $data, ComponentContext $context, ComponentResult $result): void
    {
        $existing = $this->findStockByName($name);
        $exists = $existing !== null;

        $version = $data['version'] ?? null;
        $request = new ReconciliationRequest(
            self::ALIAS,
            'stock_' . $name,
            $context->getMode(),
            $exists,
            $version ? (int) $version : null
        );

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logComment(sprintf('MSI stock "%s" exists, skipped (create mode).', $name));
            $result->recordSkipped();
            return;
        }

        if ($context->isDryRun()) {
            $this->log->logInfo(sprintf('[dry-run] Would %s MSI stock "%s".', $outcome->value, $name));
            $outcome->record($result);
            return;
        }

        $stock = $exists ? $existing : $this->stockFactory->create();
        $stock->setName($name);
        foreach ($data as $key => $value) {
            if (in_array($key, ['version', 'sources', 'sales_channels'], true)) {
                continue;
            }
            $stock->setData($key, $value);
        }

        if (!empty($data['sales_channels'])) {
            $this->assignSalesChannels($stock, (array) $data['sales_channels']);
        }

        try {
            $stockId = (int) $this->stockRepository->save($stock);
        } catch (\Exception $e) {
            throw new ComponentException((string) __('Failed to save MSI stock "%1": %2', $name, $e->getMessage()));
        }

        if (!empty($data['sources'])) {
            $this->linkSources($stockId, (array) $data['sources'], $name);
        }

        $this->log->logInfo(sprintf('MSI stock "%s" %s (ID %d).', $name, $outcome->value, $stockId));
        $this->gate->commitVersion($request, $context->isDryRun());
        $outcome->record($result);
    }

    private function findSource(string $code): ?object
    {
        try {
            return $this->sourceRepository->get($code);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function findStockByName(string $name): ?object
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('name', $name)->create();
        $items = $this->stockRepository->getList($criteria)->getItems();

        return $items === [] ? null : (current($items) ?: null);
    }

    /**
     * Assign website sales channels to the stock via its extension attributes.
     *
     * @param object $stock
     * @param array $websiteCodes
     */
    private function assignSalesChannels(object $stock, array $websiteCodes): void
    {
        $channels = [];
        foreach ($websiteCodes as $websiteCode) {
            $channel = $this->salesChannelFactory->create();
            $channel->setType(SalesChannelInterface::TYPE_WEBSITE);
            $channel->setCode((string) $websiteCode);
            $channels[] = $channel;
        }

        $extension = $stock->getExtensionAttributes();
        $extension->setSalesChannels($channels);
        $stock->setExtensionAttributes($extension);
    }

    /**
     * Link the given source codes to the stock (priority follows list order).
     *
     * @param int $stockId
     * @param array $sourceCodes
     * @param string $stockName
     */
    private function linkSources(int $stockId, array $sourceCodes, string $stockName): void
    {
        $links = [];
        $priority = 1;
        foreach ($sourceCodes as $sourceCode) {
            $link = $this->linkFactory->create();
            $link->setStockId($stockId);
            $link->setSourceCode((string) $sourceCode);
            $link->setPriority($priority++);
            $links[] = $link;
        }

        try {
            $this->linksSave->execute($links);
        } catch (\Exception $e) {
            $this->log->logError(sprintf('Failed to link sources to stock "%s": %s', $stockName, $e->getMessage()));
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
