<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Component\InventorySources;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockSearchResultsInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterfaceFactory;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventoryApi\Api\StockSourceLinksSaveInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InventorySourcesTest extends TestCase
{
    private SourceInterfaceFactory&MockObject $sourceFactory;
    private SourceRepositoryInterface&MockObject $sourceRepository;
    private StockInterfaceFactory&MockObject $stockFactory;
    private StockRepositoryInterface&MockObject $stockRepository;
    private StockSourceLinkInterfaceFactory&MockObject $linkFactory;
    private StockSourceLinksSaveInterface&MockObject $linksSave;
    private SalesChannelInterfaceFactory&MockObject $salesChannelFactory;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private LoggerInterface&MockObject $log;
    private GetSourcesAssignedToStockOrderedByPriorityInterface&MockObject $sourcesAssignedToStock;
    private InventorySources $component;

    protected function setUp(): void
    {
        $this->sourceFactory = $this->getMockBuilder(SourceInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->sourceRepository = $this->createMock(SourceRepositoryInterface::class);
        $this->stockFactory = $this->getMockBuilder(StockInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->stockRepository = $this->createMock(StockRepositoryInterface::class);
        $this->linkFactory = $this->getMockBuilder(StockSourceLinkInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->linksSave = $this->createMock(StockSourceLinksSaveInterface::class);
        $this->salesChannelFactory = $this->getMockBuilder(SalesChannelInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->sourcesAssignedToStock = $this->createMock(
            GetSourcesAssignedToStockOrderedByPriorityInterface::class
        );

        // Fluent builder: addFilter() chains, create() yields a criteria.
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new InventorySources(
            $this->sourceFactory,
            $this->sourceRepository,
            $this->stockFactory,
            $this->stockRepository,
            $this->linkFactory,
            $this->linksSave,
            $this->salesChannelFactory,
            $this->searchCriteriaBuilder,
            $this->log,
            $gate,
            $this->sourcesAssignedToStock
        );
    }

    public function testCreatesSourceWhenItDoesNotExist(): void
    {
        $this->givenNoExistingSource();

        $source = $this->createSourceMock();
        $source->expects($this->once())->method('setSourceCode')->with('default');
        $this->sourceFactory->method('create')->willReturn($source);

        $this->sourceRepository->expects($this->once())->method('save')->with($source);

        $result = $this->execute([
            'sources' => [
                'default' => ['name' => 'Default Source', 'enabled' => true],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingSource(): void
    {
        // Existing source -> create mode (no version bump) must skip it untouched.
        $this->givenExistingSource('default');

        $this->sourceFactory->expects($this->never())->method('create');
        $this->sourceRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'sources' => [
                'default' => ['name' => 'Renamed Source'],
            ],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingSource(): void
    {
        $existing = $this->givenExistingSource('default');
        // Maintain mode rewrites the existing source's columns from config.
        $existing->expects($this->once())->method('setSourceCode')->with('default');
        $existing->expects($this->atLeastOnce())->method('setData');

        $this->sourceFactory->expects($this->never())->method('create');
        $this->sourceRepository->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            'sources' => [
                'default' => ['name' => 'Renamed Source'],
            ],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testCreatesStockWithSourceLinksAndSalesChannels(): void
    {
        $this->givenNoExistingSource();
        $this->givenNoExistingStock();

        $stock = $this->createMock(StockInterface::class);
        $stock->expects($this->once())->method('setName')->with('Main Stock');
        $stock->method('getExtensionAttributes')->willReturn(
            $this->createMock(\Magento\InventoryApi\Api\Data\StockExtensionInterface::class)
        );
        $stock->method('setExtensionAttributes')->willReturnSelf();
        $this->stockFactory->method('create')->willReturn($stock);

        // A website sales channel is built for the configured website code.
        $channel = $this->createMock(SalesChannelInterface::class);
        $channel->expects($this->once())->method('setType')->with(SalesChannelInterface::TYPE_WEBSITE);
        $channel->expects($this->once())->method('setCode')->with('base');
        $this->salesChannelFactory->method('create')->willReturn($channel);

        $this->stockRepository->expects($this->once())->method('save')->with($stock)->willReturn(7);

        // The configured source code is linked to the saved stock id.
        $link = $this->createMock(StockSourceLinkInterface::class);
        $link->expects($this->once())->method('setStockId')->with(7);
        $link->expects($this->once())->method('setSourceCode')->with('default');
        $link->expects($this->once())->method('setPriority')->with(1);
        $this->linkFactory->method('create')->willReturn($link);
        $this->linksSave->expects($this->once())->method('execute')->with([$link]);

        $result = $this->execute([
            'stocks' => [
                'Main Stock' => [
                    'sources' => ['default'],
                    'sales_channels' => ['base'],
                ],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testDryRunDoesNotPersistSource(): void
    {
        $this->givenNoExistingSource();

        $this->sourceFactory->expects($this->never())->method('create');
        $this->sourceRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'sources' => [
                'default' => ['name' => 'Default Source'],
            ],
        ], true);

        // Intent is still recorded even though nothing is persisted.
        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenDataMissing(): void
    {
        $this->sourceRepository->expects($this->never())->method('save');
        $this->stockRepository->expects($this->never())->method('save');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenSourceSaveFails(): void
    {
        $this->givenNoExistingSource();

        $source = $this->createSourceMock();
        $this->sourceFactory->method('create')->willReturn($source);

        $this->sourceRepository->method('save')
            ->willThrowException(new \RuntimeException('db down'));

        $result = $this->execute([
            'sources' => [
                'default' => ['name' => 'Default Source'],
            ],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
    }

    public function testFullExportDumpsSourcesAndStocks(): void
    {
        $source = $this->createSourceMock();
        $source->method('getSourceCode')->willReturn('default');
        $source->method('getData')->willReturn(['source_code' => 'default', 'name' => 'Default Source']);
        $this->givenSourceList([$source]);

        $stock = $this->createStockMock();
        $stock->method('getName')->willReturn('Main Stock');
        $stock->method('getStockId')->willReturn(7);
        $stock->method('getData')->willReturn(['stock_id' => 7, 'name' => 'Main Stock']);
        $stock->method('getExtensionAttributes')->willReturn(null);
        $this->givenStockList([$stock]);

        $assignedSource = $this->createMock(SourceInterface::class);
        $assignedSource->method('getSourceCode')->willReturn('default');
        $this->sourcesAssignedToStock->method('execute')->willReturn([$assignedSource]);

        $out = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('default', $out['sources']);
        $this->assertSame('Default Source', $out['sources']['default']['name']);
        $this->assertArrayNotHasKey('source_code', $out['sources']['default']);

        $this->assertArrayHasKey('Main Stock', $out['stocks']);
        $this->assertArrayNotHasKey('stock_id', $out['stocks']['Main Stock']);
        $this->assertArrayNotHasKey('name', $out['stocks']['Main Stock']);
        $this->assertSame(['default'], $out['stocks']['Main Stock']['sources']);
    }

    public function testFullExportHonoursFilterPrefix(): void
    {
        $kept = $this->createSourceMock();
        $kept->method('getSourceCode')->willReturn('eu_default');
        $kept->method('getData')->willReturn(['source_code' => 'eu_default', 'name' => 'EU']);

        $dropped = $this->createSourceMock();
        $dropped->method('getSourceCode')->willReturn('us_default');
        $dropped->method('getData')->willReturn(['source_code' => 'us_default', 'name' => 'US']);

        $this->givenSourceList([$kept, $dropped]);
        $this->givenStockList([]);

        $out = $this->component->export(new ExportContext([], true, 'eu_'));

        $this->assertArrayHasKey('eu_default', $out['sources']);
        $this->assertArrayNotHasKey('us_default', $out['sources']);
    }

    public function testRefreshExportRewritesOnlyTrackedEntries(): void
    {
        // Tracked source still in the DB is rebuilt and keeps its tracked version.
        $source = $this->createSourceMock();
        $source->method('getData')->willReturn(['source_code' => 'default', 'name' => 'Fresh Name']);
        $this->sourceRepository->method('get')->willReturn($source);

        $out = $this->component->export(new ExportContext([
            'sources' => [
                'default' => ['name' => 'Stale Name', 'version' => 5],
            ],
        ], false));

        $this->assertArrayHasKey('default', $out['sources']);
        $this->assertSame('Fresh Name', $out['sources']['default']['name']);
        $this->assertSame(5, $out['sources']['default']['version']);
        // Refresh only touches tracked entries; nothing else is introduced.
        $this->assertSame(['default'], array_keys($out['sources']));
    }

    public function testRefreshExportKeepsTrackedEntryWhenEntityGone(): void
    {
        // Tracked source no longer in the DB is preserved verbatim.
        $this->sourceRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $entry = ['name' => 'Ghost Source', 'version' => 2];
        $out = $this->component->export(new ExportContext([
            'sources' => [
                'gone' => $entry,
            ],
        ], false));

        $this->assertSame($entry, $out['sources']['gone']);
    }

    /**
     * @param array $inventorySources value of the `inventory_sources` node
     * @param array|null $rawData full source override (bypasses $inventorySources)
     */
    private function execute(
        array $inventorySources,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? $inventorySources;
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    private function givenNoExistingSource(): void
    {
        $this->sourceRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('not found')));
    }

    private function givenExistingSource(string $code): SourceInterface&MockObject
    {
        $source = $this->createSourceMock();
        $source->method('getSourceCode')->willReturn($code);
        $this->sourceRepository->method('get')->willReturn($source);

        return $source;
    }

    /**
     * SourceInterface plus the magic getData/setData backed by AbstractExtensibleModel,
     * which the component uses but the API interface does not declare.
     */
    private function createSourceMock(): SourceInterface&MockObject
    {
        return $this->getMockBuilder(SourceInterface::class)
            ->disableOriginalConstructor()
            ->addMethods(['getData', 'setData'])
            ->getMockForAbstractClass();
    }

    /**
     * StockInterface plus the magic getData/setData backed by AbstractExtensibleModel.
     */
    private function createStockMock(): StockInterface&MockObject
    {
        return $this->getMockBuilder(StockInterface::class)
            ->disableOriginalConstructor()
            ->addMethods(['getData', 'setData'])
            ->getMockForAbstractClass();
    }

    private function givenNoExistingStock(): void
    {
        $results = $this->createMock(StockSearchResultsInterface::class);
        $results->method('getItems')->willReturn([]);
        $this->stockRepository->method('getList')->willReturn($results);
    }

    /**
     * @param SourceInterface[] $sources
     */
    private function givenSourceList(array $sources): void
    {
        $results = $this->createMock(
            \Magento\InventoryApi\Api\Data\SourceSearchResultsInterface::class
        );
        $results->method('getItems')->willReturn($sources);
        $this->sourceRepository->method('getList')->willReturn($results);
    }

    /**
     * @param StockInterface[] $stocks
     */
    private function givenStockList(array $stocks): void
    {
        $results = $this->createMock(StockSearchResultsInterface::class);
        $results->method('getItems')->willReturn($stocks);
        $this->stockRepository->method('getList')->willReturn($results);
    }
}
