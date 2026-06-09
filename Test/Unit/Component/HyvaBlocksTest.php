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
use Magebit\Configurator\Component\HyvaBlocks;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory as CmsBlockInterfaceFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HyvaBlocksTest extends TestCase
{
    private BlockRepositoryInterface&MockObject $cmsBlockRepository;
    private CmsBlockInterfaceFactory&MockObject $cmsBlockFactory;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private DirectoryList&MockObject $directoryList;
    private ModuleManager&MockObject $moduleManager;
    private LoggerInterface&MockObject $log;
    private HyvaBlocks $component;

    protected function setUp(): void
    {
        $this->cmsBlockRepository = $this->createMock(BlockRepositoryInterface::class);
        $this->cmsBlockFactory = $this->getMockBuilder(CmsBlockInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        // persist() writes timestamps via new \Zend_Db_Expr('NOW()'), so no date
        // helper method needs stubbing on the adapter.
        $this->connection = $this->getMockBuilder(AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->directoryList = $this->createMock(DirectoryList::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->log = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        // A Select stub that fluently chains and is harmless to inspect.
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quoteIdentifier')->willReturnArgument(0);
        // Default: cms_block has no row_id column (non-staging link field = block_id).
        $this->connection->method('tableColumnExists')->willReturn(false);

        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new HyvaBlocks(
            $this->cmsBlockRepository,
            $this->cmsBlockFactory,
            $this->resourceConnection,
            $this->directoryList,
            $this->moduleManager,
            $this->log,
            $gate
        );
    }

    public function testNoOpWhenHyvaModuleAbsent(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(false);

        // No DB activity, no block creation when the optional module is missing.
        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');
        $this->cmsBlockRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'my-block' => ['content' => '{"contentId":"0"}'],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testRecordsErrorWhenDataMissing(): void
    {
        $this->moduleManager->expects($this->never())->method('isEnabled');
        $this->connection->expects($this->never())->method('insert');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testErrorsWhenCmsBlockMissingAndNoAutoCreate(): void
    {
        $this->givenHyvaEnabled();
        $this->givenCmsBlockMissing();

        // No auto_create_block flag -> the block cannot be resolved -> error, no writes.
        $this->cmsBlockRepository->expects($this->never())->method('save');
        $this->connection->expects($this->never())->method('insert');

        $result = $this->execute([
            'missing-block' => ['content' => '{"contentId":"0"}'],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testCreatesContentWhenCmsBlockExistsButNoHyvaRow(): void
    {
        $this->givenHyvaEnabled();
        // cms_block exists (id 5); store-link lookups resolve; no Hyvä row yet.
        $this->givenCmsBlockId(5);
        $this->givenHyvaRow(false);
        $this->givenStoreLink(5, 5, []);

        // Two inserts: store association (cms_block_store) + the Hyvä content row.
        $this->connection->expects($this->exactly(2))->method('insert');
        $this->connection->expects($this->never())->method('update');
        $this->cmsBlockRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'hero' => ['content' => '{"contentId":"0"}', 'store_ids' => [0]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testAutoCreatesCmsBlockThenContent(): void
    {
        $this->givenHyvaEnabled();
        $this->givenCmsBlockMissing();
        $this->givenHyvaRow(false);
        $this->givenStoreLink(7, 7, []);

        $block = $this->createMock(BlockInterface::class);
        $this->cmsBlockFactory->expects($this->once())->method('create')->willReturn($block);

        $saved = $this->createMock(BlockInterface::class);
        $saved->method('getId')->willReturn(7);
        $this->cmsBlockRepository->expects($this->once())->method('save')->with($block)->willReturn($saved);

        $this->connection->expects($this->once())->method('insert');

        $result = $this->execute([
            'auto' => ['auto_create_block' => true, 'content' => '{"contentId":"0"}'],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingHyvaContent(): void
    {
        $this->givenHyvaEnabled();
        $this->givenCmsBlockId(5);
        // A Hyvä row already exists -> create mode must skip without writing.
        $this->givenHyvaRow([
            'id' => 1,
            'cms_block_id' => 5,
            'is_liveview_enabled' => 1,
            'draft_content' => 'old',
            'published_content' => 'old',
        ]);

        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');

        $result = $this->execute([
            'hero' => ['content' => '{"contentId":"5"}'],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingDifferingContent(): void
    {
        $this->givenHyvaEnabled();
        $this->givenCmsBlockId(5);
        $this->givenStoreLink(5, 5, [0]);
        $this->givenHyvaRow([
            'id' => 1,
            'cms_block_id' => 5,
            'is_liveview_enabled' => 1,
            'draft_content' => 'old',
            'published_content' => 'old',
        ]);

        $this->connection->expects($this->once())->method('update');
        $this->connection->expects($this->never())->method('insert');

        $result = $this->execute([
            'hero' => ['content' => 'new content', 'store_ids' => [0]],
        ], false, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedContent(): void
    {
        $this->givenHyvaEnabled();
        $this->givenCmsBlockId(5);
        $this->givenStoreLink(5, 5, [0]);
        // Existing row matches the configured content exactly -> unchanged -> skip.
        $this->givenHyvaRow([
            'id' => 1,
            'cms_block_id' => 5,
            'is_liveview_enabled' => 1,
            'draft_content' => 'same',
            'published_content' => 'same',
        ]);

        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->never())->method('insert');

        $result = $this->execute([
            'hero' => ['content' => 'same', 'store_ids' => [0]],
        ], false, ComponentMode::Maintain);

        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenHyvaEnabled();
        $this->givenCmsBlockId(5);
        $this->givenHyvaRow(false);

        // Dry-run records intent but performs no writes and no block creation.
        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');
        $this->cmsBlockRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'hero' => ['content' => '{"contentId":"5"}'],
        ], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testExportNoOpWhenHyvaModuleAbsent(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(false);
        $this->connection->expects($this->never())->method('fetchAll');

        $out = $this->component->export(new ExportContext([], true));

        $this->assertSame([], $out);
    }

    public function testFullExportDumpsAllRows(): void
    {
        $this->givenHyvaEnabled();

        // exportAll() joins hyva rows to cms_block identifiers.
        $this->connection->method('fetchAll')->willReturn([
            ['cms_block_id' => 5, 'identifier' => 'hero'],
        ]);
        // loadExisting() for block 5, then store-id lookups.
        $this->connection->method('fetchRow')->willReturn([
            'id' => 1,
            'cms_block_id' => 5,
            'is_liveview_enabled' => 1,
            'draft_content' => 'body',
            'published_content' => 'body',
        ]);
        // link id resolution then store ids
        $this->connection->method('fetchOne')->willReturn('5');
        $this->connection->method('fetchCol')->willReturn(['0']);

        $out = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('hero', $out);
        // draft === published collapses to a single content key.
        $this->assertSame('body', $out['hero']['content']);
        $this->assertTrue($out['hero']['is_liveview_enabled']);
        $this->assertSame([0], $out['hero']['store_ids']);
    }

    public function testRefreshExportOnlyRefreshesTrackedEntries(): void
    {
        $this->givenHyvaEnabled();

        // findCmsBlockId() -> link id -> store ids all come off fetchOne/fetchCol.
        $this->connection->method('fetchOne')->willReturn('5');
        $this->connection->method('fetchRow')->willReturn([
            'id' => 1,
            'cms_block_id' => 5,
            'is_liveview_enabled' => 0,
            'draft_content' => 'draft-body',
            'published_content' => 'pub-body',
        ]);
        $this->connection->method('fetchCol')->willReturn(['1', '2']);

        $existing = [
            'hero' => ['version' => 3, 'auto_create_block' => true, 'content' => 'stale'],
        ];

        $out = $this->component->export(new ExportContext($existing, false));

        $this->assertArrayHasKey('hero', $out);
        // Non-value keys are preserved.
        $this->assertSame(3, $out['hero']['version']);
        $this->assertTrue($out['hero']['auto_create_block']);
        // Differing draft/published expand to separate keys; collapsed content dropped.
        $this->assertArrayNotHasKey('content', $out['hero']);
        $this->assertSame('draft-body', $out['hero']['draft_content']);
        $this->assertSame('pub-body', $out['hero']['published_content']);
        $this->assertFalse($out['hero']['is_liveview_enabled']);
        $this->assertSame([1, 2], $out['hero']['store_ids']);
    }

    /**
     * @param array<string, mixed> $blocks data keyed by block identifier
     */
    private function execute(
        array $blocks,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $blocks);

        return $this->component->execute($context);
    }

    private function givenHyvaEnabled(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(true);
    }

    private function givenCmsBlockId(int $blockId): void
    {
        // findCmsBlockId() reads block_id via fetchOne.
        $this->connection->method('fetchOne')->willReturn((string) $blockId);
    }

    private function givenCmsBlockMissing(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function givenHyvaRow(array|false $row): void
    {
        $this->connection->method('fetchRow')->willReturn($row);
    }

    /**
     * @param int[] $existingStoreIds
     */
    private function givenStoreLink(int $blockId, int $linkId, array $existingStoreIds): void
    {
        // store associations are read via fetchCol; existing rows drive add/remove.
        $this->connection->method('fetchCol')->willReturn(array_map('strval', $existingStoreIds));
    }
}
