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
use Magebit\Configurator\Component\Pages;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

// The component references the global BP base-dir constant (Magento bootstrap defines
// it in a real app; define a stub here so source/path resolution does not fatal).
if (!defined('BP')) {
    define('BP', sys_get_temp_dir());
}

class PagesTest extends TestCase
{
    private PageRepositoryInterface&MockObject $pageRepository;
    private PageInterfaceFactory&MockObject $pageFactory;
    private StoreRepositoryInterface&MockObject $storeRepository;
    private LoggerInterface&MockObject $log;
    private Filesystem&MockObject $filesystem;
    private Escaper&MockObject $escaper;
    private ObjectManagerInterface&MockObject $objectManager;
    private ResourceConnection&MockObject $resourceConnection;
    private MetadataPool&MockObject $metadataPool;
    private Emulation&MockObject $emulation;
    private AdapterInterface&MockObject $connection;
    private VersionManagementInterface&MockObject $versionManagement;
    private Pages $component;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->pageFactory = $this->getMockBuilder(PageInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->escaper = $this->createMock(Escaper::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->metadataPool = $this->createMock(MetadataPool::class);
        $this->emulation = $this->createMock(Emulation::class);

        // A DB adapter whose fluent select() builder always chains back to itself.
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        // PageInterface metadata used to build the identifier lookup query.
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getIdentifierField')->willReturn('page_id');
        $metadata->method('getLinkField')->willReturn('row_id');
        $this->metadataPool->method('getMetadata')->willReturn($metadata);

        // Real gate over a version store that always reports "not newer".
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);

        $this->component = new Pages(
            $this->pageRepository,
            $this->pageFactory,
            $this->storeRepository,
            $this->log,
            $this->filesystem,
            $this->escaper,
            $gate,
            $this->objectManager,
            $this->resourceConnection,
            $this->metadataPool,
            $this->emulation
        );
    }

    public function testCreatesPageWhenItDoesNotExist(): void
    {
        // fetchOne yields no id -> the page does not exist -> create path.
        $this->givenPageLookup(false);
        $newPage = $this->givenPageMock([], true);
        $this->pageFactory->method('create')->willReturn($newPage);

        $newPage->expects($this->once())->method('setIdentifier')->with('about-us');
        $this->pageRepository->expects($this->once())->method('save')->with($newPage);

        $result = $this->execute([
            'about-us' => ['page' => [['title' => 'About Us']]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testCreateModeProtectsExistingPage(): void
    {
        // fetchOne yields an existing id; create mode with no version bump must skip
        // it before any load or save happens.
        $this->givenPageLookup(55);

        $this->pageFactory->expects($this->never())->method('create');
        $this->pageRepository->expects($this->never())->method('getById');
        $this->pageRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'about-us' => ['page' => [['title' => 'About Us']]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingChangedPage(): void
    {
        // Existing page whose stored title differs from the source -> maintain mode
        // loads it, applies the change and saves.
        $this->givenPageLookup(55);
        $existing = $this->givenPageMock(['title' => 'Old title'], true);
        $this->pageRepository->method('getById')->with(55)->willReturn($existing);

        $existing->expects($this->atLeastOnce())->method('setData');
        $this->pageRepository->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            'about-us' => ['page' => [['title' => 'New title']]],
        ], false, null, ComponentMode::Maintain);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedPage(): void
    {
        // Existing page already matching the source -> hasDataChanges() is false -> no
        // save and nothing recorded.
        $this->givenPageLookup(55);
        $existing = $this->givenPageMock(
            ['title' => 'Same', 'page_layout' => 'empty', 'is_active' => '1'],
            false
        );
        $this->pageRepository->method('getById')->with(55)->willReturn($existing);

        $this->pageRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'about-us' => ['page' => [['title' => 'Same']]],
        ], false, null, ComponentMode::Maintain);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(0, $result->getCreated());
    }

    public function testCreateModeVersionBumpForcesUpdate(): void
    {
        // Existing page + a newer declared version -> the gate forces an update even
        // in create mode.
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(true);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);
        $this->component = new Pages(
            $this->pageRepository,
            $this->pageFactory,
            $this->storeRepository,
            $this->log,
            $this->filesystem,
            $this->escaper,
            $gate,
            $this->objectManager,
            $this->resourceConnection,
            $this->metadataPool,
            $this->emulation
        );

        $this->givenPageLookup(55);
        $existing = $this->givenPageMock(['title' => 'Old'], true);
        $this->pageRepository->method('getById')->with(55)->willReturn($existing);

        $this->pageRepository->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            'about-us' => ['page' => [['title' => 'New', 'version' => 2]]],
        ]);

        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenPageLookup(false);
        $newPage = $this->givenPageMock([], true);
        $this->pageFactory->method('create')->willReturn($newPage);

        $this->pageRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'about-us' => ['page' => [['title' => 'About Us']]],
        ], true);

        // Dry-run still records the intent to create.
        $this->assertSame(1, $result->getCreated());
    }

    public function testEmptySourceProcessesNothing(): void
    {
        // No identifiers in the source -> nothing loaded, nothing saved, clean run.
        $this->pageFactory->expects($this->never())->method('create');
        $this->pageRepository->expects($this->never())->method('save');

        $result = $this->execute([]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testRecordsErrorWhenRequiredTitleMissing(): void
    {
        // Title is the only required field; a row lacking it raises a ComponentException
        // that surfaces as an error and prevents any save.
        $this->givenPageLookup(false);
        $this->pageFactory->method('create')->willReturn($this->givenPageMock([], true));

        $this->pageRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'about-us' => ['page' => [['meta_title' => 'No title here']]],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
        $this->assertSame(0, $result->getCreated());
    }

    public function testFullExportDumpsEveryPage(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['page_id' => 42, 'identifier' => 'about-us'],
        ]);
        // No store rows -> default scope -> no `stores` key emitted.
        $this->connection->method('fetchCol')->willReturn([]);

        $page = $this->createMock(Page::class);
        $page->method('getData')->willReturnCallback(static function (string $field = '') {
            return [
                'title' => 'About Us',
                'content_heading' => 'Welcome',
                'content' => '<p>Hi</p>',
                'page_layout' => '1column',
                'is_active' => '1',
            ][$field] ?? null;
        });
        $this->pageRepository->method('getById')->with(42)->willReturn($page);

        $out = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('about-us', $out);
        $entry = $out['about-us']['page'][0];
        $this->assertSame('About Us', $entry['title']);
        $this->assertSame('Welcome', $entry['content_heading']);
        $this->assertSame('<p>Hi</p>', $entry['content']);
        $this->assertSame('1column', $entry['page_layout']);
        $this->assertArrayNotHasKey('stores', $entry);
    }

    public function testRefreshTrackedRewritesFullEntityForTrackedIdentifiers(): void
    {
        // Default-scope tracked entry: lookup resolves an id, and refresh re-pulls the
        // full field set (including content_heading/layout not in the tracked entry)
        // while preserving structural keys like version.
        $this->givenPageLookup(42);
        $this->connection->method('fetchCol')->willReturn([]);

        $page = $this->createMock(Page::class);
        $page->method('getData')->willReturnCallback(static function (string $field = '') {
            return [
                'title' => 'Refreshed Title',
                'content_heading' => 'Fresh Heading',
                'content' => 'Refreshed body',
                'page_layout' => '2columns-left',
                'is_active' => '1',
            ][$field] ?? null;
        });
        $this->pageRepository->method('getById')->with(42)->willReturn($page);

        $tracked = [
            'about-us' => ['page' => [['title' => 'Stale', 'content' => 'old', 'version' => 5]]],
        ];

        $out = $this->component->export(new ExportContext($tracked, false));

        $this->assertArrayHasKey('about-us', $out);
        $entry = $out['about-us']['page'][0];
        $this->assertSame('Refreshed Title', $entry['title']);
        $this->assertSame('Refreshed body', $entry['content']);
        // Previously untracked fields are now captured from the DB.
        $this->assertSame('Fresh Heading', $entry['content_heading']);
        $this->assertSame('2columns-left', $entry['page_layout']);
        // Structural (non-DB) key preserved.
        $this->assertSame(5, $entry['version']);
    }

    public function testRefreshTrackedWritesSourceTemplatedContentBack(): void
    {
        // A tracked entry sourcing content from a `source` template keeps its `source`
        // reference and never inlines `content`; the DB content is written to the file
        // (suppressed here by dry-run).
        $this->givenPageLookup(42);
        $this->connection->method('fetchCol')->willReturn([]);

        $page = $this->createMock(Page::class);
        $page->method('getContent')->willReturn('templated body');
        $page->method('getData')->willReturnCallback(static function (string $field = '') {
            return [
                'title' => 'Tpl Title',
                'is_active' => '1',
            ][$field] ?? null;
        });
        $this->pageRepository->method('getById')->with(42)->willReturn($page);

        $tracked = [
            'about-us' => ['page' => [['source' => 'app/pages/about-us.phtml', 'version' => 3]]],
        ];

        // Dry-run so writeSourceContent does not touch the filesystem.
        $out = $this->component->export(new ExportContext($tracked, false, null, true));

        $entry = $out['about-us']['page'][0];
        $this->assertSame('Tpl Title', $entry['title']);
        $this->assertSame('app/pages/about-us.phtml', $entry['source']);
        $this->assertArrayNotHasKey('content', $entry);
        $this->assertSame(3, $entry['version']);
    }

    public function testRefreshTrackedKeepsDefinitionWhenPageMissing(): void
    {
        // Lookup yields no id -> the tracked definition is returned unchanged.
        $this->givenPageLookup(false);

        $this->pageRepository->expects($this->never())->method('getById');

        $tracked = [
            'gone' => ['page' => [['title' => 'Stays', 'content' => 'as-is']]],
        ];

        $out = $this->component->export(new ExportContext($tracked, false));

        $this->assertSame($tracked['gone']['page'][0], $out['gone']['page'][0]);
    }

    public function testRefreshTrackedKeepsFilteredEntriesUntouched(): void
    {
        // Filter excludes "blog/*"; the entry passes through verbatim and no DB lookup
        // is performed for it.
        $this->connection->expects($this->never())->method('fetchOne');

        $existing = [
            'blog/post' => ['page' => [['title' => 'Kept', 'version' => 1]]],
        ];

        $out = $this->component->export(new ExportContext($existing, false, 'about'));

        $this->assertSame($existing['blog/post'], $out['blog/post']);
    }

    public function testRemoveDeletesExistingPage(): void
    {
        // `remove: true` on an existing page deletes it (in either mode) and never
        // loads/saves it through the create/update path.
        $this->givenPageLookup(55);

        $this->pageFactory->expects($this->never())->method('create');
        $this->pageRepository->expects($this->never())->method('save');
        $this->pageRepository->expects($this->once())->method('deleteById')->with(55);

        $result = $this->execute([
            'about-us' => ['page' => [['remove' => true]]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemoveSkipsWhenPageAbsent(): void
    {
        // `remove: true` on a page that does not exist is idempotent: skip, no delete.
        $this->givenPageLookup(false);

        $this->pageRepository->expects($this->never())->method('deleteById');
        $this->pageRepository->expects($this->never())->method('save');

        $result = $this->execute([
            'about-us' => ['page' => [['remove' => true]]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testRemoveDryRunDoesNotDelete(): void
    {
        // Dry-run records the removal intent but performs no delete.
        $this->givenPageLookup(55);

        $this->pageRepository->expects($this->never())->method('deleteById');

        $result = $this->execute([
            'about-us' => ['page' => [['remove' => true]]],
        ], true);

        $this->assertSame(1, $result->getRemoved());
    }

    /**
     * @param array $pages source data keyed by identifier
     * @param array|null $rawData full source override (bypasses $pages)
     */
    private function execute(
        array $pages,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? $pages;
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * Stub the identifier->id lookup. A truthy int marks the page as existing; false
     * marks it as new.
     */
    private function givenPageLookup(int|false $pageId): void
    {
        $this->connection->method('fetchOne')->willReturn($pageId === false ? false : (string) $pageId);
    }

    /**
     * A Page mock usable as the new or the existing page. Given field values back
     * getData() so the diff logic resolves naturally; missing keys return null (so
     * configured values are treated as changes). hasDataChanges() drives whether a
     * save occurs.
     *
     * @param array<string, mixed> $data
     */
    private function givenPageMock(array $data, bool $hasChanges): Page&MockObject
    {
        // setStores() is a Magento magic data-setter (not declared on Page), so it must
        // be registered via addMethods(); the rest are real declared methods.
        $page = $this->getMockBuilder(Page::class)
            ->disableOriginalConstructor()
            ->addMethods(['setStores'])
            ->onlyMethods(['getData', 'getId', 'setData', 'setIdentifier', 'unsetData', 'hasDataChanges'])
            ->getMock();
        $page->method('getData')->willReturnCallback(
            static fn (string $key = '') => $data[$key] ?? null
        );
        $page->method('getId')->willReturn($data['page_id'] ?? null);
        $page->method('setData')->willReturnSelf();
        $page->method('setIdentifier')->willReturnSelf();
        $page->method('setStores')->willReturnSelf();
        $page->method('unsetData')->willReturnSelf();
        $page->method('hasDataChanges')->willReturn($hasChanges);

        return $page;
    }
}
