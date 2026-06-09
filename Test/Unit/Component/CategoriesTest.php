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
use Magebit\Configurator\Component\Categories;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Eav\Model\Entity\Type as EntityType;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Model\Group;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\ResourceModel\Group\Collection as GroupCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoriesTest extends TestCase
{
    private CategoryFactory&MockObject $categoryFactory;
    private GroupFactory&MockObject $groupFactory;
    private DirectoryList&MockObject $dirList;
    private LoggerInterface&MockObject $log;
    private BlockFactory&MockObject $blockFactory;
    private BlockResource&MockObject $blockResource;
    private CategoryResource&MockObject $categoryResource;
    private Categories $component;

    protected function setUp(): void
    {
        $this->categoryFactory = $this->getMockBuilder(CategoryFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->groupFactory = $this->getMockBuilder(GroupFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->dirList = $this->createMock(DirectoryList::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->blockFactory = $this->getMockBuilder(BlockFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->blockResource = $this->createMock(BlockResource::class);
        $this->categoryResource = $this->createMock(CategoryResource::class);

        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new Categories(
            $this->categoryFactory,
            $this->groupFactory,
            $this->dirList,
            $this->log,
            $this->blockFactory,
            $this->blockResource,
            $this->categoryResource,
            $gate
        );
    }

    public function testRecordsErrorWhenCategoriesNodeMissing(): void
    {
        $this->categoryResource->expects($this->never())->method('save');

        $result = $this->execute(['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenStoreGroupNotFound(): void
    {
        // No matching store group -> getDefaultCategory throws -> error, no save.
        $this->givenStoreGroup(0, 2);
        $this->categoryResource->expects($this->never())->method('save');

        $result = $this->execute([
            'categories' => [
                ['store_group' => 'Nope', 'categories' => [['name' => 'Shirts']]],
            ],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testCreatesCategoryWhenItDoesNotExist(): void
    {
        $this->givenStoreGroup(1, 2);
        $root = $this->givenRootCategory(2);

        // The lookup of the configured child returns a brand new (id-less) category.
        $newCategory = $this->givenNewCategory();
        $this->givenChildLookupReturns($newCategory);

        $this->categoryFactory->method('create')->willReturnOnConsecutiveCalls(
            $root,        // getDefaultCategory loads the root
            $newCategory  // createOrUpdateCategory looks up the child to create
        );

        $this->categoryResource->expects($this->once())->method('save')->with($newCategory);

        $result = $this->execute([
            'categories' => [
                ['store_group' => 'Main Website Store', 'categories' => [['name' => 'Shirts']]],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testCreateModeProtectsExistingCategory(): void
    {
        $this->givenStoreGroup(1, 2);
        $root = $this->givenRootCategory(2);

        // The child already exists (has an id) -> create mode must skip, no save.
        $existing = $this->givenExistingCategory(55);
        $this->givenChildLookupReturns($existing);

        $this->categoryFactory->method('create')->willReturnOnConsecutiveCalls($root, $existing);

        $this->categoryResource->expects($this->never())->method('save');

        $result = $this->execute([
            'categories' => [
                ['store_group' => 'Main Website Store', 'categories' => [['name' => 'Shirts']]],
            ],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingCategory(): void
    {
        $this->givenStoreGroup(1, 2);
        $root = $this->givenRootCategory(2);

        $existing = $this->givenExistingCategory(55);
        $this->givenChildLookupReturns($existing);

        $this->categoryFactory->method('create')->willReturnOnConsecutiveCalls($root, $existing);

        $this->categoryResource->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            'categories' => [
                ['store_group' => 'Main Website Store', 'categories' => [['name' => 'Shirts']]],
            ],
        ], false, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenStoreGroup(1, 2);
        $root = $this->givenRootCategory(2);

        $newCategory = $this->givenNewCategory();
        $this->givenChildLookupReturns($newCategory);

        $this->categoryFactory->method('create')->willReturnOnConsecutiveCalls($root, $newCategory);

        // Dry-run records the intent but never touches the resource model.
        $this->categoryResource->expects($this->never())->method('save');

        $result = $this->execute([
            'categories' => [
                ['store_group' => 'Main Website Store', 'categories' => [['name' => 'Shirts']]],
            ],
        ], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreatesNestedChildCategory(): void
    {
        $this->givenStoreGroup(1, 2);
        $root = $this->givenRootCategory(2);

        // Each created category's own lookup collection yields itself, so the
        // category written for a node is the one the factory just produced.
        $parent = $this->givenNewCategory();
        $this->givenChildLookupReturns($parent);
        $child = $this->givenNewCategory();
        $this->givenChildLookupReturns($child);

        // create() calls: root load, parent ("Shirts") lookup, child ("Polo Shirts") lookup.
        $this->categoryFactory->method('create')->willReturnOnConsecutiveCalls($root, $parent, $child);

        $this->categoryResource->expects($this->exactly(2))->method('save');

        $result = $this->execute([
            'categories' => [
                [
                    'store_group' => 'Main Website Store',
                    'categories' => [
                        [
                            'name' => 'Shirts',
                            'categories' => [['name' => 'Polo Shirts']],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getCreated());
    }

    public function testFullExportDumpsTreeUnderEachGroup(): void
    {
        // One store group "Main Website Store" with root category id 2.
        $group = $this->createMock(Group::class);
        $group->method('getName')->willReturn('Main Website Store');
        $group->method('getRootCategoryId')->willReturn(2);

        $groupCollection = $this->createMock(GroupCollection::class);
        $groupCollection->method('addFieldToFilter')->willReturnSelf();
        $groupCollection->method('getSize')->willReturn(1);
        $groupCollection->method('getFirstItem')->willReturn($group);
        // Full export also iterates the group collection directly.
        $groupCollection->method('getIterator')->willReturn(new \ArrayIterator([$group]));

        $groupModel = $this->createMock(Group::class);
        $groupModel->method('getCollection')->willReturn($groupCollection);
        $this->groupFactory->method('create')->willReturn($groupModel);

        // Root category (id 2) with a single child "Shirts" (id 10), no grandchildren.
        $root = $this->createMock(Category::class);
        $root->method('getId')->willReturn(2);

        $shirts = $this->createMock(Category::class);
        $shirts->method('getId')->willReturn(10);
        $shirts->method('getName')->willReturn('Shirts');
        $shirts->method('getData')->willReturnCallback(
            static fn (string $field) => ['is_active' => '1', 'url_key' => 'shirts'][$field] ?? null
        );

        $rootChildrenCollection = $this->givenChildrenCollection([$shirts]);
        $shirtsChildrenCollection = $this->givenChildrenCollection([]);

        $root->method('getCollection')->willReturn($rootChildrenCollection);
        $shirts->method('getCollection')->willReturn($shirtsChildrenCollection);

        // create() calls: getDefaultCategory loads root, then exportChildren for root,
        // then exportChildren for the shirts child.
        $this->categoryFactory->method('create')->willReturnOnConsecutiveCalls($root, $root, $shirts);
        $root->method('load')->willReturnSelf();

        $result = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('categories', $result);
        $this->assertCount(1, $result['categories']);
        $this->assertSame('Main Website Store', $result['categories'][0]['store_group']);

        $children = $result['categories'][0]['categories'];
        $this->assertCount(1, $children);
        $this->assertSame('Shirts', $children[0]['name']);
        $this->assertSame('1', $children[0]['is_active']);
        $this->assertSame('shirts', $children[0]['url_key']);
        $this->assertArrayNotHasKey('categories', $children[0]);
    }

    public function testRefreshExportKeepsUntrackedAndPreservesExtraKeys(): void
    {
        // Tracked group with one tracked category "Shirts" carrying a non-exported
        // key (version) that must survive the refresh.
        $existing = [
            'categories' => [
                [
                    'store_group' => 'Main Website Store',
                    'categories' => [
                        ['name' => 'Shirts', 'version' => 3],
                    ],
                ],
            ],
        ];

        $group = $this->createMock(Group::class);
        $group->method('getName')->willReturn('Main Website Store');
        $group->method('getRootCategoryId')->willReturn(2);

        $groupCollection = $this->createMock(GroupCollection::class);
        $groupCollection->method('addFieldToFilter')->willReturnSelf();
        $groupCollection->method('getSize')->willReturn(1);
        $groupCollection->method('getFirstItem')->willReturn($group);

        $groupModel = $this->createMock(Group::class);
        $groupModel->method('getCollection')->willReturn($groupCollection);
        $this->groupFactory->method('create')->willReturn($groupModel);

        $root = $this->createMock(Category::class);
        $root->method('getId')->willReturn(2);
        $root->method('load')->willReturnSelf();

        // findChildByName locates "Shirts" -> id 10.
        $shirtsLookup = $this->createMock(Category::class);
        $shirtsLookup->method('getId')->willReturn(10);
        $findCollection = $this->createMock(CategoryCollection::class);
        $findCollection->method('addAttributeToSelect')->willReturnSelf();
        $findCollection->method('addFieldToFilter')->willReturnSelf();
        $findCollection->method('setPageSize')->willReturnSelf();
        $findCollection->method('getFirstItem')->willReturn($shirtsLookup);
        $root->method('getCollection')->willReturn($findCollection);

        // The loaded "Shirts" category with its current admin fields and no children.
        $shirts = $this->createMock(Category::class);
        $shirts->method('getId')->willReturn(10);
        $shirts->method('getName')->willReturn('Shirts');
        $shirts->method('getData')->willReturnCallback(
            static fn (string $field) => $field === 'is_active' ? '1' : null
        );
        $shirts->method('load')->willReturnSelf();
        $shirts->method('getCollection')->willReturn($this->givenChildrenCollection([]));

        // create() calls: getDefaultCategory root, findChildByName root, load shirts,
        // exportChildren for shirts.
        $this->categoryFactory->method('create')->willReturnOnConsecutiveCalls($root, $root, $shirts, $shirts);

        $result = $this->component->export(new ExportContext($existing, false));

        $this->assertArrayHasKey('categories', $result);
        $entry = $result['categories'][0]['categories'][0];
        $this->assertSame('Shirts', $entry['name']);
        $this->assertSame('1', $entry['is_active']);
        // Non-exported tracked key must be preserved.
        $this->assertSame(3, $entry['version']);
    }

    /**
     * @param array $data full source array passed to the component
     */
    private function execute(
        array $data,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * Configure the store-group lookup used by getDefaultCategory().
     */
    private function givenStoreGroup(int $size, int $rootCategoryId): void
    {
        $group = $this->createMock(Group::class);
        $group->method('getRootCategoryId')->willReturn($rootCategoryId);

        $collection = $this->createMock(GroupCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn($size);
        $collection->method('getFirstItem')->willReturn($group);

        $groupModel = $this->createMock(Group::class);
        $groupModel->method('getCollection')->willReturn($collection);
        $this->groupFactory->method('create')->willReturn($groupModel);
    }

    /**
     * The root category returned by getDefaultCategory()->load().
     */
    private function givenRootCategory(int $id): Category&MockObject
    {
        $root = $this->createMock(Category::class);
        $root->method('getId')->willReturn($id);
        $root->method('getPath')->willReturn('1/' . $id);
        $root->method('load')->willReturnSelf();

        return $root;
    }

    /**
     * A not-yet-persisted category (no id) ready to be configured and saved.
     */
    private function givenNewCategory(): Category&MockObject
    {
        return $this->makeWritableCategory(null);
    }

    /**
     * An already-persisted category with the given id.
     */
    private function givenExistingCategory(int $id): Category&MockObject
    {
        return $this->makeWritableCategory($id);
    }

    /**
     * Build a category mock that can absorb every setter the writer path calls,
     * including the getResource()->getEntityType()->getDefaultAttributeSetId() chain.
     */
    private function makeWritableCategory(?int $id): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn('Shirts');
        $category->method('getPath')->willReturn($id === null ? '' : '1/2/' . $id);
        $category->method('getLevel')->willReturn(2);
        $category->method('getStoreId')->willReturn(0);
        $category->method('setData')->willReturnSelf();

        $entityType = $this->createMock(EntityType::class);
        $entityType->method('getDefaultAttributeSetId')->willReturn(3);
        $resource = $this->createMock(CategoryResource::class);
        $resource->method('getEntityType')->willReturn($entityType);
        $category->method('getResource')->willReturn($resource);

        return $category;
    }

    /**
     * Wire the child-lookup collection so getFirstItem() yields the given category.
     */
    private function givenChildLookupReturns(Category&MockObject $category): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($category);
        $category->method('getCollection')->willReturn($collection);
    }

    /**
     * A children collection (used by exportChildren) yielding the given categories.
     *
     * @param Category[] $children
     */
    private function givenChildrenCollection(array $children): CategoryCollection&MockObject
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($children));

        return $collection;
    }
}
