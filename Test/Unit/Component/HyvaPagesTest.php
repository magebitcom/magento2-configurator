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
use Magebit\Configurator\Component\HyvaPages;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Cms\Model\Page as CmsPage;
use Magento\Cms\Model\PageFactory as CmsPageFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HyvaPagesTest extends TestCase
{
    private const HYVA_MODULE = 'Hyva_CmsMagento';

    private CmsPageFactory&MockObject $cmsPageFactory;
    private ResourceConnection&MockObject $resourceConnection;
    private DirectoryList&MockObject $directoryList;
    private ModuleManager&MockObject $moduleManager;
    private LoggerInterface&MockObject $log;
    private AdapterInterface&MockObject $connection;
    private ObjectManagerInterface&MockObject $objectManager;
    private HyvaPages $component;

    protected function setUp(): void
    {
        $this->cmsPageFactory = $this->getMockBuilder(CmsPageFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->directoryList = $this->createMock(DirectoryList::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);

        // A DB adapter whose fluent select() builder always chains back to itself.
        // persist() writes timestamps via new \Zend_Db_Expr('NOW()'), so no date
        // helper method needs stubbing on the adapter.
        $this->connection = $this->getMockBuilder(AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quoteIdentifier')->willReturnArgument(0);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new HyvaPages(
            $this->cmsPageFactory,
            $this->resourceConnection,
            $this->directoryList,
            $this->moduleManager,
            $this->log,
            $gate,
            $this->objectManager
        );
    }

    public function testNoOpWhenHyvaModuleAbsent(): void
    {
        // Module disabled -> execute is a no-op: no DB work, empty successful result.
        $this->moduleManager->method('isEnabled')->with(self::HYVA_MODULE)->willReturn(false);

        $this->cmsPageFactory->expects($this->never())->method('create');
        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');

        $result = $this->execute(['home' => ['content' => '{"a":1}']]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testCreatesContentWhenNoHyvaRowExists(): void
    {
        $this->givenModuleEnabled();
        $this->givenCmsPage('home', 42);
        $this->givenNoExistingHyvaRow();

        $this->connection->expects($this->once())->method('insert');
        $this->connection->expects($this->never())->method('update');

        $result = $this->execute(['home' => ['content' => '{"draft":true}']]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingChangedRow(): void
    {
        $this->givenModuleEnabled();
        $this->givenCmsPage('home', 42);
        // Existing row whose content differs from the source -> create mode skips it.
        $this->givenExistingHyvaRow([
            'id' => 7,
            'is_liveview_enabled' => 1,
            'draft_content' => '{"old":true}',
            'published_content' => '{"old":true}',
        ]);

        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');

        $result = $this->execute(['home' => ['content' => '{"new":true}']]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingChangedRow(): void
    {
        $this->givenModuleEnabled();
        $this->givenCmsPage('home', 42);
        $this->givenExistingHyvaRow([
            'id' => 7,
            'is_liveview_enabled' => 1,
            'draft_content' => '{"old":true}',
            'published_content' => '{"old":true}',
        ]);

        $this->connection->expects($this->once())->method('update');
        $this->connection->expects($this->never())->method('insert');

        $result = $this->execute(
            ['home' => ['content' => '{"new":true}']],
            false,
            null,
            ComponentMode::Maintain
        );

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedRow(): void
    {
        $this->givenModuleEnabled();
        $this->givenCmsPage('home', 42);
        // Row already matches source content + liveview flag -> unchanged -> skip.
        $this->givenExistingHyvaRow([
            'id' => 7,
            'is_liveview_enabled' => 1,
            'draft_content' => '{"same":true}',
            'published_content' => '{"same":true}',
        ]);

        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->never())->method('insert');

        $result = $this->execute(
            ['home' => ['content' => '{"same":true}', 'is_liveview_enabled' => true]],
            false,
            null,
            ComponentMode::Maintain
        );

        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenModuleEnabled();
        $this->givenCmsPage('home', 42);
        $this->givenNoExistingHyvaRow();

        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');

        $result = $this->execute(['home' => ['content' => '{"draft":true}']], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenCmsPageMissing(): void
    {
        $this->givenModuleEnabled();
        // CMS page lookup yields no id -> the row cannot be attached.
        $this->givenCmsPage('ghost', null);

        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');

        $result = $this->execute(['ghost' => ['content' => '{"x":1}']]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRecordsErrorWhenDataNodeMissing(): void
    {
        // Empty source data is rejected before the module check.
        $this->connection->expects($this->never())->method('insert');
        $this->connection->expects($this->never())->method('update');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testExportReturnsEmptyWhenModuleAbsent(): void
    {
        $this->moduleManager->method('isEnabled')->with(self::HYVA_MODULE)->willReturn(false);

        $context = new ExportContext([], true, null, false);

        $this->assertSame([], $this->component->export($context));
    }

    public function testFullExportDumpsEveryRow(): void
    {
        $this->givenModuleEnabled();

        // draft == published collapses to a single inline `content` key.
        $this->connection->method('fetchAll')->willReturn([
            [
                'identifier' => 'home',
                'draft_content' => '{"v":1}',
                'published_content' => '{"v":1}',
                'is_liveview_enabled' => 1,
            ],
            [
                'identifier' => 'about',
                'draft_content' => '{"draft":1}',
                'published_content' => '{"pub":1}',
                'is_liveview_enabled' => 0,
            ],
        ]);

        $context = new ExportContext([], true, null, false);
        $out = $this->component->export($context);

        $this->assertArrayHasKey('home', $out);
        $this->assertArrayHasKey('about', $out);
        $this->assertSame(['is_liveview_enabled' => true, 'content' => '{"v":1}'], $out['home']);
        $this->assertSame(
            ['is_liveview_enabled' => false, 'draft_content' => '{"draft":1}', 'published_content' => '{"pub":1}'],
            $out['about']
        );
    }

    public function testRefreshExportRewritesOnlyTrackedEntries(): void
    {
        $this->givenModuleEnabled();
        $this->givenCmsPage('home', 42);

        // Tracked entry carries a structural key (version) that must be preserved,
        // while the DB-backed content is rebuilt from the current row.
        $this->connection->method('fetchRow')->willReturn([
            'id' => 7,
            'is_liveview_enabled' => 1,
            'draft_content' => '{"fresh":1}',
            'published_content' => '{"fresh":1}',
        ]);

        $existing = [
            'home' => ['content' => '{"stale":1}', 'version' => 3],
        ];
        $context = new ExportContext($existing, false, null, false);
        $out = $this->component->export($context);

        $this->assertArrayHasKey('home', $out);
        $this->assertSame('{"fresh":1}', $out['home']['content']);
        $this->assertSame(3, $out['home']['version']);
        $this->assertTrue($out['home']['is_liveview_enabled']);
    }

    public function testRefreshExportPreservesSourceFileReferences(): void
    {
        $this->givenModuleEnabled();
        $this->givenCmsPage('home', 42);

        $this->connection->method('fetchRow')->willReturn([
            'id' => 7,
            'is_liveview_enabled' => 1,
            'draft_content' => '{"fresh":1}',
            'published_content' => '{"fresh":1}',
        ]);

        // A `content_source` reference cannot be rewritten from the DB: it is kept
        // and the inline content the full shaping would emit is dropped.
        $existing = [
            'home' => ['content_source' => 'hyva/home.json', 'version' => 5],
        ];
        $context = new ExportContext($existing, false, null, false);
        $out = $this->component->export($context);

        $this->assertSame('hyva/home.json', $out['home']['content_source']);
        $this->assertArrayNotHasKey('content', $out['home']);
        $this->assertSame(5, $out['home']['version']);
    }

    public function testRefreshExportKeepsUntrackedFilteredEntriesUntouched(): void
    {
        $this->givenModuleEnabled();

        // Filter excludes "blog/*"; the entry is passed through verbatim and no
        // CMS lookup is performed for it.
        $this->cmsPageFactory->expects($this->never())->method('create');

        $existing = [
            'blog/post' => ['content' => '{"kept":1}', 'version' => 1],
        ];
        $context = new ExportContext($existing, false, 'home', false);
        $out = $this->component->export($context);

        $this->assertSame($existing['blog/post'], $out['blog/post']);
    }

    /**
     * @param array $pages value of the top-level `hyva_pages` node (keyed by identifier)
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

    private function givenModuleEnabled(): void
    {
        $this->moduleManager->method('isEnabled')->with(self::HYVA_MODULE)->willReturn(true);
    }

    private function givenCmsPage(string $identifier, ?int $pageId): void
    {
        $page = $this->createMock(CmsPage::class);
        $page->method('load')->with($identifier, 'identifier')->willReturnSelf();
        $page->method('getId')->willReturn($pageId);
        $this->cmsPageFactory->method('create')->willReturn($page);
    }

    private function givenNoExistingHyvaRow(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function givenExistingHyvaRow(array $row): void
    {
        $this->connection->method('fetchRow')->willReturn($row);
    }
}
