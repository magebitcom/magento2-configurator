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
use Magebit\Configurator\Component\Websites;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Framework\Event\ManagerInterface;
use Magento\Indexer\Model\IndexerFactory;
use Magento\Store\Model\Group;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebsitesTest extends TestCase
{
    private IndexerFactory&MockObject $indexer;
    private ManagerInterface&MockObject $eventManager;
    private WebsiteFactory&MockObject $websiteFactory;
    private StoreFactory&MockObject $storeFactory;
    private GroupFactory&MockObject $groupFactory;
    private LoggerInterface&MockObject $log;
    private Websites $component;

    protected function setUp(): void
    {
        $this->indexer = $this->getMockBuilder(IndexerFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->websiteFactory = $this->getMockBuilder(WebsiteFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->storeFactory = $this->getMockBuilder(StoreFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->groupFactory = $this->getMockBuilder(GroupFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->log = $this->createMock(LoggerInterface::class);

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new Websites(
            $this->indexer,
            $this->eventManager,
            $this->websiteFactory,
            $this->storeFactory,
            $this->groupFactory,
            $this->log,
            $gate
        );
    }

    public function testCreatesFullHierarchyWhenNothingExists(): void
    {
        // Nothing exists: website, group, store view all load empty (getId null).
        $websiteResource = $this->createMock(WebsiteResource::class);
        $websiteResource->expects($this->once())->method('save');
        $website = $this->givenWebsite(null, ['name' => 'Main Website'], $websiteResource);

        $groupResource = $this->createMock(GroupResource::class);
        // Saved once on create + once when (re)pointing the default store.
        $groupResource->expects($this->exactly(2))->method('save');
        // New group: getId stays falsy (null) throughout creation.
        $group = $this->givenGroup(null, ['name' => 'Main Store'], $groupResource);
        $group->method('getId')->willReturn(null);
        $group->method('getDefaultStoreId')->willReturn(null);

        $storeResource = $this->createMock(StoreResource::class);
        $storeResource->expects($this->once())->method('save');
        // New store: no existing group id, so it matches the new group's null id (no repoint).
        $storeView = $this->givenStore(null, ['name' => 'Default'], $storeResource, null);

        // setDefaultStore reloads the default store view by code; null group id matches the new group.
        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(7);
        $defaultStore->method('getStoreGroupId')->willReturn(null);
        $defaultStore->method('getCode')->willReturn('default');

        // storeFactory->create() is called for the store view, then again for default store lookup.
        $this->storeFactory->method('create')->willReturnOnConsecutiveCalls($storeView, $defaultStore);
        $this->websiteFactory->method('create')->willReturn($website);
        $this->groupFactory->method('create')->willReturn($group);

        // Creating new scope entities flags a price reindex; provide the indexer process.
        $this->givenIndexer();

        $result = $this->execute($this->sampleConfig());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $result->getCreated());
    }

    public function testCreateModeProtectsExistingWebsite(): void
    {
        // Website already exists -> create mode skips modifying it (gate isSkip).
        $website = $this->givenWebsite(1, ['name' => 'Main Website'], null);
        $this->websiteFactory->method('create')->willReturn($website);

        // Existing group is likewise protected.
        $group = $this->givenGroup(11, ['name' => 'Main Store'], null);
        $group->method('getDefaultStoreId')->willReturn(7);
        $this->groupFactory->method('create')->willReturn($group);

        // Store view already exists -> protected too.
        $storeView = $this->givenStore(7, ['name' => 'Default'], null);

        // default-store lookup reloads the same code, belongs to group, already set.
        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(7);
        $defaultStore->method('getStoreGroupId')->willReturn(11);
        $defaultStore->method('getCode')->willReturn('default');
        $this->storeFactory->method('create')->willReturnOnConsecutiveCalls($storeView, $defaultStore);

        $result = $this->execute($this->sampleConfig());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        // website + group + storeview all skipped.
        $this->assertSame(3, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingDifferingWebsite(): void
    {
        // Existing website with a name that differs from the source -> maintain updates it.
        $websiteResource = $this->createMock(WebsiteResource::class);
        $websiteResource->expects($this->once())->method('save');
        $website = $this->givenWebsite(1, ['name' => 'Old Name'], $websiteResource);
        $this->websiteFactory->method('create')->willReturn($website);

        // Existing group unchanged, existing store unchanged -> they skip; default store already set.
        $group = $this->givenGroup(11, ['name' => 'Main Store'], null);
        $group->method('getDefaultStoreId')->willReturn(7);
        $this->groupFactory->method('create')->willReturn($group);

        $storeView = $this->givenStore(7, ['name' => 'Default'], null);
        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(7);
        $defaultStore->method('getStoreGroupId')->willReturn(11);
        $defaultStore->method('getCode')->willReturn('default');
        $this->storeFactory->method('create')->willReturnOnConsecutiveCalls($storeView, $defaultStore);

        $result = $this->execute($this->sampleConfig(), false, null, ComponentMode::Maintain);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        // Brand-new hierarchy in dry-run: intent recorded, but nothing saved.
        $websiteResource = $this->createMock(WebsiteResource::class);
        $websiteResource->expects($this->never())->method('save');
        $website = $this->givenWebsite(null, ['name' => 'Main Website'], $websiteResource);
        $this->websiteFactory->method('create')->willReturn($website);

        $groupResource = $this->createMock(GroupResource::class);
        $groupResource->expects($this->never())->method('save');
        $group = $this->givenGroup(null, ['name' => 'Main Store'], $groupResource);
        $group->method('getId')->willReturn(null);
        $group->method('getDefaultStoreId')->willReturn(null);
        $this->groupFactory->method('create')->willReturn($group);

        $storeResource = $this->createMock(StoreResource::class);
        $storeResource->expects($this->never())->method('save');
        $storeView = $this->givenStore(null, ['name' => 'Default'], $storeResource, null);

        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(7);
        $defaultStore->method('getStoreGroupId')->willReturn(null);
        $defaultStore->method('getCode')->willReturn('default');
        $this->storeFactory->method('create')->willReturnOnConsecutiveCalls($storeView, $defaultStore);

        $result = $this->execute($this->sampleConfig(), true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $result->getCreated());
    }

    public function testRecordsErrorWhenWebsitesNodeMissing(): void
    {
        $this->websiteFactory->expects($this->never())->method('create');

        $result = $this->execute([], ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testFullExportEmitsHierarchyWithoutInternalIds(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getName')->willReturn('Default');
        $store->method('getIsActive')->willReturn(1);

        $group = $this->createMock(Group::class);
        $group->method('getId')->willReturn(11);
        $group->method('getName')->willReturn('Main Store');
        $group->method('getRootCategoryId')->willReturn(2);
        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(7);
        $defaultStore->method('getCode')->willReturn('default');
        $group->method('getDefaultStore')->willReturn($defaultStore);
        $group->method('getStores')->willReturn([$store]);

        $website = $this->createMock(Website::class);
        $website->method('getCode')->willReturn('base');
        $website->method('getName')->willReturn('Main Website');
        $website->method('getGroups')->willReturn([$group]);

        // websiteFactory->create()->getCollection() yields the website list.
        $collectionWebsite = $this->createMock(Website::class);
        $collectionWebsite->method('getCollection')->willReturn([$website]);
        $this->websiteFactory->method('create')->willReturn($collectionWebsite);

        $out = $this->component->export(new ExportContext([], true, null, false));

        $this->assertArrayHasKey('websites', $out);
        $this->assertArrayHasKey('base', $out['websites']);

        $entry = $out['websites']['base'];
        $this->assertSame('Main Website', $entry['name']);
        $this->assertArrayNotHasKey('website_id', $entry);
        $this->assertArrayNotHasKey('code', $entry);

        $groupEntry = $entry['store_groups'][0];
        $this->assertSame('Main Store', $groupEntry['name']);
        $this->assertSame('default', $groupEntry['default_store']);

        $viewEntry = $groupEntry['store_views']['default'];
        $this->assertSame('Default', $viewEntry['name']);
        $this->assertSame(1, $viewEntry['is_active']);
    }

    public function testFullExportFiltersByCodePrefix(): void
    {
        $matching = $this->createMock(Website::class);
        $matching->method('getCode')->willReturn('eu_site');
        $matching->method('getName')->willReturn('EU');
        $matching->method('getGroups')->willReturn([]);

        $other = $this->createMock(Website::class);
        $other->method('getCode')->willReturn('us_site');

        $collectionWebsite = $this->createMock(Website::class);
        $collectionWebsite->method('getCollection')->willReturn([$matching, $other]);
        $this->websiteFactory->method('create')->willReturn($collectionWebsite);

        $out = $this->component->export(new ExportContext([], true, 'eu_', false));

        $this->assertArrayHasKey('eu_site', $out['websites']);
        $this->assertArrayNotHasKey('us_site', $out['websites']);
    }

    public function testRefreshExportRefreshesOnlyTrackedAndExcludesIds(): void
    {
        $existing = [
            'websites' => [
                'base' => [
                    'name' => 'Old Name',
                    'store_groups' => [
                        [
                            'name' => 'Main Store',
                            'default_store' => 'default',
                            'store_views' => [
                                'default' => ['name' => 'Old View'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Tracked website refreshes scalar columns; internal ids must be dropped.
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(1);
        $website->method('getData')->willReturnCallback(
            fn (string $key = '') => $key === '' ? [
                'website_id' => 1,
                'code' => 'base',
                'name' => 'Main Website',
                'default_group_id' => 11,
            ] : null
        );

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(7);
        $store->method('getCode')->willReturn('default');
        $store->method('getData')->willReturnCallback(
            fn (string $key = '') => $key === '' ? [
                'store_id' => 7,
                'website_id' => 1,
                'group_id' => 11,
                'code' => 'default',
                'name' => 'Default',
                'is_active' => 1,
            ] : null
        );

        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(7);
        $defaultStore->method('getCode')->willReturn('default');

        $group = $this->createMock(Group::class);
        $group->method('getId')->willReturn(11);
        $group->method('getName')->willReturn('Main Store');
        $group->method('getDefaultStore')->willReturn($defaultStore);
        $group->method('getData')->willReturnCallback(
            fn (string $key = '') => $key === '' ? [
                'group_id' => 11,
                'website_id' => 1,
                'name' => 'Main Store',
                'root_category_id' => 2,
            ] : null
        );

        // Group is resolved by name within the website's groups.
        $website->method('getGroups')->willReturn([$group]);

        $this->websiteFactory->method('create')->willReturn($website);
        $this->groupFactory->method('create')->willReturn($group);
        $this->storeFactory->method('create')->willReturn($store);

        $out = $this->component->export(new ExportContext($existing, false, null, false));

        $entry = $out['websites']['base'];
        $this->assertSame('Main Website', $entry['name']);
        $this->assertArrayNotHasKey('website_id', $entry);
        $this->assertArrayNotHasKey('default_group_id', $entry);
        $this->assertArrayNotHasKey('code', $entry);

        $groupEntry = $entry['store_groups'][0];
        $this->assertArrayNotHasKey('website_id', $groupEntry);
        $this->assertSame('default', $groupEntry['default_store']);

        $viewEntry = $groupEntry['store_views']['default'];
        $this->assertSame('Default', $viewEntry['name']);
        $this->assertArrayNotHasKey('store_id', $viewEntry);
        $this->assertArrayNotHasKey('group_id', $viewEntry);
        $this->assertArrayNotHasKey('code', $viewEntry);
    }

    public function testRefreshExportKeepsTrackedEntryWhenRecordGone(): void
    {
        $existing = [
            'websites' => [
                'gone' => ['name' => 'Removed Website', 'store_groups' => []],
            ],
        ];

        // Website no longer in the DB (getId null) -> tracked entry kept untouched.
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(null);
        $this->websiteFactory->method('create')->willReturn($website);

        $out = $this->component->export(new ExportContext($existing, false, null, false));

        $this->assertSame($existing, $out);
    }

    /**
     * Build the context and run execute().
     *
     * @param array $websites value of the `websites` node
     * @param array|null $rawData full source override (bypasses $websites)
     */
    private function execute(
        array $websites,
        bool|array $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        // Allow passing a raw-data override as the second positional arg for the missing-node case.
        if (is_array($dryRun)) {
            $rawData = $dryRun;
            $dryRun = false;
        }

        $data = $rawData ?? ['websites' => $websites];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * @return array minimal one-website / one-group / one-store-view config
     */
    private function sampleConfig(): array
    {
        return [
            'base' => [
                'name' => 'Main Website',
                'store_groups' => [
                    [
                        'name' => 'Main Store',
                        'default_store' => 'default',
                        'store_views' => [
                            'default' => ['name' => 'Default'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Stub the indexer factory so a triggered price reindex runs without error.
     */
    private function givenIndexer(): void
    {
        $indexProcess = $this->getMockBuilder(\Magento\Indexer\Model\Indexer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'reindexAll'])
            ->getMock();
        $indexProcess->method('load')->willReturnSelf();
        $this->indexer->method('create')->willReturn($indexProcess);
    }

    /**
     * @param array<string,mixed> $data data the model reports via getData()
     */
    private function givenWebsite(?int $id, array $data, ?WebsiteResource $resource): Website&MockObject
    {
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn($id);
        $website->method('load')->willReturnSelf();
        $website->method('setData')->willReturnSelf();
        $website->method('setCode')->willReturnSelf();
        $website->method('getData')->willReturn($data);
        if ($resource !== null) {
            $website->method('getResource')->willReturn($resource);
        }

        return $website;
    }

    /**
     * @param array<string,mixed> $data data the model reports via getData()
     */
    private function givenGroup(?int $id, array $data, ?GroupResource $resource): Group&MockObject
    {
        $group = $this->createMock(Group::class);
        // getId may be overridden by callers that need a concrete value post-create.
        if ($id !== null) {
            $group->method('getId')->willReturn($id);
        }
        $group->method('load')->willReturnSelf();
        $group->method('setData')->willReturnSelf();
        $group->method('setWebsite')->willReturnSelf();
        $group->method('getData')->willReturn($data);
        $group->method('getName')->willReturn($data['name'] ?? '');
        if ($resource !== null) {
            $group->method('getResource')->willReturn($resource);
        }

        return $group;
    }

    /**
     * @param array<string,mixed> $data data the model reports via getData()
     */
    private function givenStore(?int $id, array $data, ?StoreResource $resource, ?int $groupId = 11): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('load')->willReturnSelf();
        $store->method('setData')->willReturnSelf();
        $store->method('setCode')->willReturnSelf();
        $store->method('setGroup')->willReturnSelf();
        $store->method('getData')->willReturn($data);
        // Store view's group id; matched to the group so no repoint occurs in the exists path.
        $store->method('getStoreGroupId')->willReturn($groupId);
        $store->method('getCode')->willReturn($data['code'] ?? 'default');
        if ($resource !== null) {
            $store->method('getResource')->willReturn($resource);
        }

        return $store;
    }
}
