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
use Magebit\Configurator\Component\Blocks;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\ResourceModel\Block\Collection;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

// The component references the global BP base-dir constant when writing `source:`
// content files; define a stub so path resolution does not fatal under unit tests.
if (!defined('BP')) {
    define('BP', sys_get_temp_dir());
}

class BlocksTest extends TestCase
{
    private BlockInterfaceFactory&MockObject $blockFactory;
    private BlockRepositoryInterface&MockObject $blockRepository;
    private Store&MockObject $storeManager;
    private LoggerInterface&MockObject $log;
    private Filesystem&MockObject $filesystem;
    private Escaper&MockObject $escaper;
    private ObjectManagerInterface&MockObject $objectManager;
    private VersionManagementInterface&MockObject $versionManagement;
    private Blocks $component;

    protected function setUp(): void
    {
        $this->blockFactory = $this->getMockBuilder(BlockInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->blockRepository = $this->createMock(BlockRepositoryInterface::class);
        $this->storeManager = $this->createMock(Store::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->escaper = $this->createMock(Escaper::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);

        // Real gate over a version store that reports "not newer" by default.
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);

        $this->component = new Blocks(
            $this->blockFactory,
            $this->blockRepository,
            $this->storeManager,
            $this->log,
            $this->filesystem,
            $this->escaper,
            $gate,
            $this->objectManager
        );
    }

    public function testCreatesBlockWhenItDoesNotExist(): void
    {
        // Empty collection -> no existing block -> create path.
        $collection = $this->givenCollection([]);
        $newBlock = $this->givenBlockMock();

        // First create() yields the collection source, second yields the new block.
        $this->blockFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->givenCollectionSource($collection),
            $newBlock
        );

        $newBlock->expects($this->once())->method('setIdentifier')->with('my-block');
        $this->blockRepository->expects($this->once())->method('save')->with($newBlock);

        $result = $this->execute([
            'my-block' => ['block' => [['title' => 'Hello', 'content' => 'World']]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testCreateModeProtectsExistingBlock(): void
    {
        // One existing block, no stores -> getBlockToProcess returns it. Create
        // mode with no version bump must skip it without saving.
        $existing = $this->givenBlockMock(['title' => 'Old']);
        $collection = $this->givenCollection([$existing]);

        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $this->blockRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'my-block' => ['block' => [['title' => 'New title']]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingChangedBlock(): void
    {
        // Existing block whose title differs -> maintain mode updates and saves.
        $existing = $this->givenBlockMock(['title' => 'Old']);
        $collection = $this->givenCollection([$existing]);

        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $existing->expects($this->atLeastOnce())->method('setData');
        $this->blockRepository->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            'my-block' => ['block' => [['title' => 'New title']]],
        ], false, ComponentMode::Maintain);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedBlock(): void
    {
        // Existing block already matching the config -> no field differs -> no save,
        // and since the block is neither created nor updated nothing is recorded.
        $existing = $this->givenBlockMock(['title' => 'Same']);
        $collection = $this->givenCollection([$existing]);

        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $this->blockRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'my-block' => ['block' => [['title' => 'Same']]],
        ], false, ComponentMode::Maintain);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testCreateModeVersionBumpForcesUpdate(): void
    {
        // Existing block + a newer declared version -> the gate forces an update
        // even in create mode.
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(true);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);
        $this->component = new Blocks(
            $this->blockFactory,
            $this->blockRepository,
            $this->storeManager,
            $this->log,
            $this->filesystem,
            $this->escaper,
            $gate,
            $this->objectManager
        );

        $existing = $this->givenBlockMock(['title' => 'Old']);
        $collection = $this->givenCollection([$existing]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $this->blockRepository->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            'my-block' => ['block' => [['title' => 'New', 'version' => 2]]],
        ]);

        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $collection = $this->givenCollection([]);
        $newBlock = $this->givenBlockMock();
        $this->blockFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->givenCollectionSource($collection),
            $newBlock
        );

        $this->blockRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'my-block' => ['block' => [['title' => 'Hello']]],
        ], true);

        // Dry-run still records the intent to create.
        $this->assertSame(1, $result->getCreated());
    }

    public function testEmptySourceProcessesNothing(): void
    {
        // No identifiers in the source -> nothing loaded, nothing saved, clean run.
        $this->blockFactory->expects($this->never())->method('create');
        $this->blockRepository->expects($this->never())->method('save');

        $result = $this->execute([]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testExportAllWritesContentToSourceFile(): void
    {
        $block = $this->getMockBuilder(Block::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIdentifier', 'getTitle', 'getContent'])
            ->addMethods(['getIsActive', 'getStoreId'])
            ->getMock();
        $block->method('getIdentifier')->willReturn('footer-links');
        $block->method('getTitle')->willReturn('Footer Links');
        $block->method('getContent')->willReturn('<p>links</p>');
        $block->method('getIsActive')->willReturn(1);
        $block->method('getStoreId')->willReturn([0]); // default scope -> no stores key

        $collection = $this->givenIterableCollection([$block]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        // Full export + dry-run: content is referenced via `source:` (written to an
        // external .html file), never inlined; dry-run keeps the filesystem untouched.
        $out = $this->component->export(new ExportContext([], true, null, true));

        $this->assertArrayHasKey('footer-links', $out);
        $this->assertSame([
            'title' => 'Footer Links',
            'source' => 'app/etc/configurator/Blocks/content/footer-links.html',
            'is_active' => 1,
        ], $out['footer-links']['block'][0]);
        $this->assertArrayNotHasKey('content', $out['footer-links']['block'][0]);
        $this->assertArrayNotHasKey('stores', $out['footer-links']['block'][0]);
    }

    public function testRefreshTrackedRewritesOnlyTrackedIdentifiers(): void
    {
        // Refresh mode reads only the tracked identifier, re-pulling DB fields and
        // preserving structural keys (e.g. version) on the entry.
        $existing = $this->getMockBuilder(Block::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTitle', 'getContent'])
            ->addMethods(['getIsActive', 'getStoreId'])
            ->getMock();
        $existing->method('getTitle')->willReturn('Refreshed Title');
        $existing->method('getContent')->willReturn('Refreshed content');
        $existing->method('getIsActive')->willReturn(0);
        $existing->method('getStoreId')->willReturn([0]);

        $collection = $this->givenCollection([$existing]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $tracked = [
            'my-block' => ['block' => [['title' => 'Stale', 'content' => 'old', 'version' => 5]]],
        ];

        $out = $this->component->export(new ExportContext($tracked, false));

        $this->assertArrayHasKey('my-block', $out);
        $entry = $out['my-block']['block'][0];
        $this->assertSame('Refreshed Title', $entry['title']);
        $this->assertSame('Refreshed content', $entry['content']);
        $this->assertSame(0, $entry['is_active']);
        // Structural (non-DB) key preserved.
        $this->assertSame(5, $entry['version']);
    }

    public function testRefreshTrackedWritesSourceTemplatedContentBack(): void
    {
        // A tracked entry using a `source` template gets the DB content written
        // to that file (not inlined) and the entry keeps the `source` key without
        // a `content` key.
        $existing = $this->getMockBuilder(Block::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTitle', 'getContent'])
            ->addMethods(['getIsActive', 'getStoreId'])
            ->getMock();
        $existing->method('getTitle')->willReturn('Tpl Title');
        $existing->method('getContent')->willReturn('templated content');
        $existing->method('getIsActive')->willReturn(1);
        $existing->method('getStoreId')->willReturn([0]);

        $collection = $this->givenCollection([$existing]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $tracked = [
            'tpl-block' => ['block' => [['source' => 'app/blocks/tpl-block.phtml']]],
        ];

        // Dry-run so writeSourceContent does not touch the filesystem.
        $out = $this->component->export(new ExportContext($tracked, false, null, true));

        $entry = $out['tpl-block']['block'][0];
        $this->assertSame('Tpl Title', $entry['title']);
        $this->assertSame('app/blocks/tpl-block.phtml', $entry['source']);
        $this->assertArrayNotHasKey('content', $entry);
    }

    public function testRefreshTrackedKeepsDefinitionWhenBlockMissing(): void
    {
        // No matching DB block -> the tracked definition is returned unchanged.
        $empty = $this->givenCollection([]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($empty));

        $tracked = [
            'gone' => ['block' => [['title' => 'Stays', 'content' => 'as-is']]],
        ];

        $out = $this->component->export(new ExportContext($tracked, false));

        $this->assertSame($tracked['gone'], $out['gone']);
    }

    public function testRemoveDeletesExistingBlock(): void
    {
        // `remove: true` on an existing block deletes it (in either mode) without
        // going through the create/update path.
        $existing = $this->givenBlockMock(['title' => 'Old']);
        $collection = $this->givenCollection([$existing]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $this->blockRepository->expects($this->never())->method('save');
        $this->blockRepository->expects($this->once())->method('deleteById')->with(1);

        $result = $this->execute([
            'my-block' => ['block' => [['remove' => true]]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemoveSkipsWhenBlockAbsent(): void
    {
        // `remove: true` on a block that does not exist is idempotent: skip, no delete.
        $collection = $this->givenCollection([]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $this->blockRepository->expects($this->never())->method('deleteById');
        $this->blockRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'my-block' => ['block' => [['remove' => true]]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testRemoveDryRunDoesNotDelete(): void
    {
        // Dry-run records the removal intent but performs no delete.
        $existing = $this->givenBlockMock(['title' => 'Old']);
        $collection = $this->givenCollection([$existing]);
        $this->blockFactory->method('create')->willReturn($this->givenCollectionSource($collection));

        $this->blockRepository->expects($this->never())->method('deleteById');

        $result = $this->execute([
            'my-block' => ['block' => [['remove' => true]]],
        ], true);

        $this->assertSame(1, $result->getRemoved());
    }

    /**
     * @param array $blocks value of the source data (keyed by block identifier)
     */
    private function execute(
        array $blocks,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext(
            'test.yaml',
            $mode,
            'test',
            $dryRun,
            static fn (): array => $blocks
        );

        return $this->component->execute($context);
    }

    /**
     * A Block mock usable both as the new block and as an existing block. Given
     * field values back getData() so the diff logic resolves naturally; missing
     * keys return null (so every configured value is treated as a change).
     *
     * @param array<string, mixed> $data
     */
    private function givenBlockMock(array $data = []): Block&MockObject
    {
        $block = $this->getMockBuilder(Block::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getId', 'setData', 'setIdentifier', 'unsetData'])
            ->addMethods(['setStoreId', 'setStores'])
            ->getMock();
        $block->method('getData')->willReturnCallback(
            static fn (string $key = '') => $data[$key] ?? null
        );
        $block->method('getId')->willReturn(1);
        $block->method('setData')->willReturnSelf();
        $block->method('setIdentifier')->willReturnSelf();
        $block->method('setStoreId')->willReturnSelf();
        $block->method('unsetData')->willReturnSelf();
        $block->method('setStores')->willReturnSelf();

        return $block;
    }

    /**
     * Build a Collection mock seeded with the given items: count() reflects size
     * and getFirstItem() returns the first item (used by getBlockToProcess()).
     *
     * @param array<int, Block&MockObject> $items
     */
    private function givenCollection(array $items): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addStoreFilter')->willReturnSelf();
        $collection->method('count')->willReturn(count($items));
        $collection->method('getFirstItem')->willReturn($items[0] ?? $this->createMock(Block::class));

        return $collection;
    }

    /**
     * An iterable Collection mock (for exportAll(), which foreach-iterates it).
     *
     * @param array<int, Block&MockObject> $items
     */
    private function givenIterableCollection(array $items): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        return $collection;
    }

    /**
     * A Block mock whose getCollection() yields the supplied collection — this is
     * what blockFactory->create() returns when the component only needs a
     * collection handle.
     */
    private function givenCollectionSource(Collection&MockObject $collection): Block&MockObject
    {
        $source = $this->createMock(Block::class);
        $source->method('getCollection')->willReturn($collection);

        return $source;
    }
}
