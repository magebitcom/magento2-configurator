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
use Magebit\Configurator\Component\Widgets;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreFactory;
use Magento\Theme\Model\ResourceModel\Theme\Collection as ThemeCollection;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory;
use Magento\Theme\Model\Theme;
use Magento\Widget\Model\ResourceModel\Widget\Instance as WidgetInstanceResource;
use Magento\Widget\Model\ResourceModel\Widget\Instance\Collection as WidgetCollection;
use Magento\Widget\Model\Widget\Instance;
use Magento\Widget\Model\Widget\InstanceFactory as WidgetInstanceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WidgetsTest extends TestCase
{
    private WidgetCollection&MockObject $widgetCollection;
    private WidgetInstanceFactory&MockObject $widgetFactory;
    private StoreFactory&MockObject $storeFactory;
    private ThemeCollectionFactory&MockObject $themeCollection;
    private SerializerInterface&MockObject $serializer;
    private LoggerInterface&MockObject $log;
    private AppState&MockObject $appState;
    private BlockRepositoryInterface&MockObject $blockRepository;
    private SearchCriteriaBuilder&MockObject $criteriaBuilder;
    private WidgetInstanceResource&MockObject $widgetResource;
    private Widgets $component;

    protected function setUp(): void
    {
        $this->widgetCollection = $this->createMock(WidgetCollection::class);
        $this->widgetFactory = $this->getMockBuilder(WidgetInstanceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->storeFactory = $this->getMockBuilder(StoreFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->themeCollection = $this->getMockBuilder(ThemeCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->appState = $this->createMock(AppState::class);
        $this->blockRepository = $this->createMock(BlockRepositoryInterface::class);
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->widgetResource = $this->createMock(WidgetInstanceResource::class);

        // The fluent criteria builder used by block-identifier resolution.
        $this->criteriaBuilder->method('addFilter')->willReturnSelf();
        $this->criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        // emulateAreaCode runs the supplied callback inline so the resource save fires.
        $this->appState->method('emulateAreaCode')->willReturnCallback(
            static fn (string $area, callable $callback) => $callback()
        );

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new Widgets(
            $this->widgetCollection,
            $this->widgetFactory,
            $this->storeFactory,
            $this->themeCollection,
            $this->serializer,
            $this->log,
            $this->appState,
            $this->blockRepository,
            $this->criteriaBuilder,
            $this->widgetResource,
            $gate
        );
    }

    public function testCreatesWidgetWhenItDoesNotExist(): void
    {
        $this->givenWidgetCollection([]);

        // Fresh instance: every getData() is null so each configured field is applied.
        $widget = $this->instanceMock();
        $widget->method('getData')->willReturn(null);
        $widget->method('getTitle')->willReturn('Promo Banner');
        $widget->expects($this->exactly(2))->method('setData');
        $this->widgetFactory->method('create')->willReturn($widget);

        $this->widgetResource->expects($this->once())->method('save')->with($widget);

        $result = $this->execute([
            ['instance_type' => 'Magento\\Banner\\Block\\Widget\\Banner', 'title' => 'Promo Banner'],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingWidget(): void
    {
        $existing = $this->givenExistingWidget('Magento\\Banner\\Block\\Widget\\Banner', 'Promo Banner');

        // Create mode: existing widget is left untouched and not even diffed.
        $existing->expects($this->never())->method('setData');
        $this->widgetFactory->expects($this->never())->method('create');
        $this->widgetResource->expects($this->never())->method('save');

        $result = $this->execute([
            ['instance_type' => 'Magento\\Banner\\Block\\Widget\\Banner', 'title' => 'Promo Banner'],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingChangedWidget(): void
    {
        $existing = $this->givenExistingWidget('Magento\\Banner\\Block\\Widget\\Banner', 'Promo Banner');
        // Identity fields already match storage; only css_class differs, so just that field is re-applied.
        $existing->method('getData')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'instance_type' => 'Magento\\Banner\\Block\\Widget\\Banner',
                'title' => 'Promo Banner',
                'css_class' => 'old-css',
                default => null,
            }
        );
        $existing->method('getTitle')->willReturn('Promo Banner');
        $existing->expects($this->once())->method('setData')->with('css_class', 'new-css');

        $this->widgetFactory->expects($this->never())->method('create');
        $this->widgetResource->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            [
                'instance_type' => 'Magento\\Banner\\Block\\Widget\\Banner',
                'title' => 'Promo Banner',
                'css_class' => 'new-css',
            ],
        ], false, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedWidget(): void
    {
        $existing = $this->givenExistingWidget('Magento\\Banner\\Block\\Widget\\Banner', 'Promo Banner');
        // Every configured field already matches storage -> nothing to save.
        $existing->method('getData')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'instance_type' => 'Magento\\Banner\\Block\\Widget\\Banner',
                'title' => 'Promo Banner',
                'css_class' => 'same-css',
                default => null,
            }
        );
        $existing->expects($this->never())->method('setData');

        $this->widgetResource->expects($this->never())->method('save');

        $result = $this->execute([
            [
                'instance_type' => 'Magento\\Banner\\Block\\Widget\\Banner',
                'title' => 'Promo Banner',
                'css_class' => 'same-css',
            ],
        ], false, ComponentMode::Maintain);

        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenWidgetCollection([]);

        $widget = $this->instanceMock();
        $widget->method('getData')->willReturn(null);
        $widget->method('getTitle')->willReturn('Promo Banner');
        $this->widgetFactory->method('create')->willReturn($widget);

        $this->widgetResource->expects($this->never())->method('save');

        $result = $this->execute([
            ['instance_type' => 'Magento\\Banner\\Block\\Widget\\Banner', 'title' => 'Promo Banner'],
        ], true);

        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenDataEmpty(): void
    {
        $this->widgetResource->expects($this->never())->method('save');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testBuildPageGroupsPlacesLayoutAndBlock(): void
    {
        // page_group placement (layout_handle/for) + block reference are carried through.
        $built = $this->component->buildPageGroups([
            [
                'page_group' => 'pages',
                'block' => 'content',
                'layout_handle' => 'catalog_product_view',
                'for' => 'specific',
                'page_id' => '5',
            ],
            // Minimal entry falls back to all_pages defaults.
            ['block' => 'sidebar'],
        ]);

        $this->assertSame('pages', $built[0]['page_group']);
        $this->assertSame('catalog_product_view', $built[0]['pages']['layout_handle']);
        $this->assertSame('specific', $built[0]['pages']['for']);
        $this->assertSame('content', $built[0]['pages']['block']);
        $this->assertSame('5', $built[0]['pages']['page_id']);

        $this->assertSame('all_pages', $built[1]['page_group']);
        $this->assertSame('default', $built[1]['all_pages']['layout_handle']);
        $this->assertSame('all', $built[1]['all_pages']['for']);
        $this->assertSame('sidebar', $built[1]['all_pages']['block']);
    }

    public function testPopulateWidgetParametersResolvesBlockIdentifier(): void
    {
        // block_identifier is resolved to a concrete block_id, then serialized.
        $block = $this->createMock(BlockInterface::class);
        $block->method('getId')->willReturn(42);

        $results = $this->createMock(BlockSearchResultsInterface::class);
        $results->method('getTotalCount')->willReturn(1);
        $results->method('getItems')->willReturn([$block]);
        $this->blockRepository->method('getList')->willReturn($results);

        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($this->callback(
                static fn (array $params): bool =>
                    !isset($params['block_identifier'])
                    && ($params['block_id'] ?? null) === '42'
            ))
            ->willReturn('{"block_id":"42"}');

        $serialized = $this->component->populateWidgetParameters(['block_identifier' => 'venta-faq']);

        $this->assertSame('{"block_id":"42"}', $serialized);
    }

    public function testGetThemeIdResolvesThemeCodeToId(): void
    {
        $theme = $this->createMock(Theme::class);
        $theme->method('getId')->willReturn(7);

        $collection = $this->createMock(ThemeCollection::class);
        $collection->method('addFilter')->willReturnSelf();
        $collection->method('count')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($theme);
        $this->themeCollection->method('create')->willReturn($collection);

        $this->assertSame(7, $this->component->getThemeId('Magento/blank'));
    }

    public function testFullExportDumpsEveryWidgetInstance(): void
    {
        $a = $this->givenCollectionWidget('Type\\A', 1);
        $b = $this->givenCollectionWidget('Type\\B', 2);
        $this->givenWidgetCollection([$a, $b]);

        // Each collection id is loaded into a fresh instance carrying that id's type.
        $this->givenLoadableInstancesById([1 => 'Type\\A', 2 => 'Type\\B']);

        $exported = $this->component->export(new ExportContext([], true));

        $this->assertCount(2, $exported);
        $this->assertSame('Type\\A', $exported[0]['instance_type']);
        $this->assertSame('Type\\B', $exported[1]['instance_type']);
    }

    public function testFullExportRespectsInstanceTypePrefixFilter(): void
    {
        $a = $this->givenCollectionWidget('Vendor\\Keep\\Widget', 1);
        $b = $this->givenCollectionWidget('Vendor\\Drop\\Widget', 2);
        $this->givenWidgetCollection([$a, $b]);

        // Only the matching widget (id 1) is loaded; map its id to its type.
        $this->givenLoadableInstancesById([1 => 'Vendor\\Keep\\Widget']);

        $exported = $this->component->export(new ExportContext([], true, 'Vendor\\Keep'));

        $this->assertCount(1, $exported);
        $this->assertSame('Vendor\\Keep\\Widget', $exported[0]['instance_type']);
    }

    public function testRefreshExportRebuildsOnlyTrackedEntries(): void
    {
        // Tracked entry whose widget still exists -> refreshed from DB (version preserved).
        $tracked = $this->givenCollectionWidget('Type\\A', 1);
        $tracked->method('getTitle')->willReturn('Tracked');
        // Tracked entry whose widget is gone -> kept verbatim.
        $this->givenWidgetCollection([$tracked]);

        $this->givenLoadableInstancesById([1 => 'Type\\A']);

        $existing = [
            ['instance_type' => 'Type\\A', 'title' => 'Tracked', 'version' => 3],
            ['instance_type' => 'Type\\Gone', 'title' => 'Orphan'],
        ];

        $exported = $this->component->export(new ExportContext($existing, false));

        $this->assertCount(2, $exported);
        $this->assertSame('Type\\A', $exported[0]['instance_type']);
        $this->assertSame(3, $exported[0]['version']);
        // Untracked/missing widget is passed through unchanged.
        $this->assertSame($existing[1], $exported[1]);
    }

    /**
     * @param array $widgets list of widget arrays (the top-level source data)
     */
    private function execute(
        array $widgets,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $widgets);

        return $this->component->execute($context);
    }

    /**
     * Make the (iterable) widget collection yield the given items.
     *
     * @param array $items
     */
    private function givenWidgetCollection(array $items): void
    {
        $this->widgetCollection->method('getIterator')->willReturn(new \ArrayIterator($items));
    }

    /**
     * Register an existing widget in the collection and return its mock so the
     * caller can constrain its behaviour.
     */
    private function givenExistingWidget(string $instanceType, string $title): Instance&MockObject
    {
        $widget = $this->instanceMock();
        $widget->method('getInstanceType')->willReturn($instanceType);
        $widget->method('getTitle')->willReturn($title);
        $this->givenWidgetCollection([$widget]);

        return $widget;
    }

    /**
     * A lightweight collection row exposing instance_type + id for export.
     */
    private function givenCollectionWidget(string $instanceType, int $id): Instance&MockObject
    {
        $widget = $this->instanceMock();
        $widget->method('getInstanceType')->willReturn($instanceType);
        $widget->method('getId')->willReturn($id);

        return $widget;
    }

    /**
     * A freshly loaded Instance with no theme/stores/parameters/page_groups so
     * buildEntry() emits just the identity keys.
     */
    private function givenLoadableInstance(string $instanceType = 'Type\\A'): Instance&MockObject
    {
        $instance = $this->instanceMock();
        $instance->method('getInstanceType')->willReturn($instanceType);
        $instance->method('getTitle')->willReturn('Tracked');
        $instance->method('getThemeId')->willReturn(0);
        $instance->method('getData')->willReturn(null);

        return $instance;
    }

    /**
     * Wire the factory + resource so that loadInstance() yields a distinct
     * instance per id, each carrying its mapped instance_type. The factory
     * returns instances in the order the ids will be loaded (collection order
     * after any prefix filtering), which is exactly the order of the map.
     *
     * @param array<int,string> $instanceTypesById
     */
    private function givenLoadableInstancesById(array $instanceTypesById): void
    {
        $queue = [];
        foreach ($instanceTypesById as $instanceType) {
            $queue[] = $this->givenLoadableInstance($instanceType);
        }

        $this->widgetFactory->method('create')->willReturnCallback(
            static function () use (&$queue): Instance {
                return array_shift($queue);
            }
        );
        $this->widgetResource->method('load')->willReturnArgument(0);
    }

    /**
     * Mock a widget Instance. Its accessors (getInstanceType/getTitle/getThemeId)
     * are magic __call methods on the underlying model, so they are registered via
     * addMethods(); getData/setData/getId/load are real and stay onlyMethods().
     */
    private function instanceMock(): Instance&MockObject
    {
        return $this->getMockBuilder(Instance::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getData', 'setData'])
            ->addMethods(['getInstanceType', 'getTitle', 'getThemeId'])
            ->getMock();
    }
}
