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
use Magebit\Configurator\Component\ReviewRating;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Review\Model\Rating;
use Magento\Review\Model\RatingFactory;
use Magento\Review\Model\Rating\Entity;
use Magento\Review\Model\Rating\EntityFactory;
use Magento\Review\Model\Rating\Option;
use Magento\Review\Model\Rating\OptionFactory;
use Magento\Review\Model\ResourceModel\Rating as RatingResource;
use Magento\Review\Model\ResourceModel\Rating\Collection as RatingCollection;
use Magento\Review\Model\ResourceModel\Rating\Option as RatingOptionResource;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewRatingTest extends TestCase
{
    private RatingFactory&MockObject $ratingFactory;
    private StoreRepositoryInterface&MockObject $storeRepository;
    private OptionFactory&MockObject $optionFactory;
    private EntityFactory&MockObject $entityFactory;
    private RatingResource&MockObject $ratingResource;
    private RatingOptionResource&MockObject $optionResource;
    private LoggerInterface&MockObject $log;
    private ReviewRating $component;

    protected function setUp(): void
    {
        $this->ratingFactory = $this->getMockBuilder(RatingFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->optionFactory = $this->getMockBuilder(OptionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->entityFactory = $this->getMockBuilder(EntityFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->ratingResource = $this->createMock(RatingResource::class);
        $this->optionResource = $this->createMock(RatingOptionResource::class);
        $this->log = $this->createMock(LoggerInterface::class);

        // The 'product' review entity always resolves to id 1.
        $entity = $this->createMock(Entity::class);
        $entity->method('getIdByCode')->with('product')->willReturn('1');
        $this->entityFactory->method('create')->willReturn($entity);

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new ReviewRating(
            $this->ratingFactory,
            $this->storeRepository,
            $this->optionFactory,
            $this->entityFactory,
            $this->ratingResource,
            $this->optionResource,
            $this->log,
            $gate
        );
    }

    public function testCreatesRatingWhenItDoesNotExist(): void
    {
        $rating = $this->getMockBuilder(Rating::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getOptions', 'getId', 'setData', 'setEntityId'])
            ->addMethods(['setRatingCode', 'setStores', 'getRatingCode'])
            ->getMock();
        $rating->method('load')->willReturnSelf();
        $rating->expects($this->once())->method('setRatingCode')->with('Quality');
        $rating->expects($this->once())->method('setEntityId')->with(1);
        $rating->expects($this->once())->method('setStores')->with([]);
        // No options exist yet -> all five get created.
        $rating->method('getOptions')->willReturn([]);
        // First getId() (existence check) is falsy -> create; later calls (option
        // wiring) report the persisted id.
        $rating->method('getId')->willReturnOnConsecutiveCalls(0, 7, 7, 7, 7, 7);
        $this->ratingFactory->method('create')->willReturn($rating);

        $option = $this->createMock(Option::class);
        $this->optionFactory->method('create')->willReturn($option);

        $this->ratingResource->expects($this->once())->method('save')->with($rating);
        $this->optionResource->expects($this->exactly(ReviewRating::MAX_NUM_RATINGS))->method('save');

        $result = $this->execute(['Quality' => ['is_active' => 1, 'position' => 1]]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingRating(): void
    {
        // Existing rating (has an id) + create mode + no version bump -> skip.
        $this->givenLoadedRating(42);

        $this->ratingResource->expects($this->never())->method('save');
        $this->optionResource->expects($this->never())->method('save');

        $result = $this->execute(['Quality' => ['is_active' => 1]]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingRating(): void
    {
        $rating = $this->givenLoadedRating(42);
        $rating->expects($this->once())->method('setRatingCode')->with('Quality');
        $rating->method('getOptions')->willReturn([]);

        $option = $this->createMock(Option::class);
        $this->optionFactory->method('create')->willReturn($option);

        $this->ratingResource->expects($this->once())->method('save')->with($rating);

        $result = $this->execute(['Quality' => ['is_active' => 1]], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testResolvesStoreCodesToIds(): void
    {
        $rating = $this->givenLoadedRating(0);
        $rating->method('getOptions')->willReturn([]);
        // The configured store codes are translated to their ids before assignment.
        $rating->expects($this->once())->method('setStores')->with([5]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(5);
        $this->storeRepository->method('get')->with('default')->willReturn($store);

        $this->optionFactory->method('create')->willReturn($this->createMock(Option::class));
        $this->ratingResource->expects($this->once())->method('save')->with($rating);

        $result = $this->execute(['Quality' => ['stores' => ['default']]]);

        $this->assertSame(1, $result->getCreated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $rating = $this->givenLoadedRating(0);
        $rating->method('getOptions')->willReturn([]);

        // Dry-run records intent but writes nothing.
        $this->ratingResource->expects($this->never())->method('save');
        $this->optionResource->expects($this->never())->method('save');
        $this->optionFactory->expects($this->never())->method('create');

        $result = $this->execute(['Quality' => ['is_active' => 1]], true);

        $this->assertSame(1, $result->getCreated());
        $this->assertTrue($result->isSuccessful());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->ratingFactory->expects($this->never())->method('create');
        $this->ratingResource->expects($this->never())->method('save');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenSaveThrows(): void
    {
        $rating = $this->givenLoadedRating(0);
        $rating->method('getOptions')->willReturn([]);

        $this->ratingResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $result = $this->execute(['Quality' => ['is_active' => 1]]);

        $this->assertFalse($result->isSuccessful());
        $this->assertContains('db down', $result->getErrors());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemovesExistingRating(): void
    {
        // Existing rating flagged for removal -> delete called once, no save.
        $rating = $this->givenLoadedRating(42);

        $this->ratingResource->expects($this->once())->method('delete')->with($rating);
        $this->ratingResource->expects($this->never())->method('save');
        $this->optionResource->expects($this->never())->method('save');

        $result = $this->execute(['Quality' => ['remove' => true]]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testRemoveAbsentRatingIsSkipped(): void
    {
        // Rating not present -> nothing deleted, recorded as a skip (idempotent).
        $this->givenLoadedRating(0);

        $this->ratingResource->expects($this->never())->method('delete');
        $this->ratingResource->expects($this->never())->method('save');

        $result = $this->execute(['Quality' => ['remove' => true]]);

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getRemoved());
    }

    public function testDryRunDoesNotDeleteRating(): void
    {
        // Dry-run records the removal intent but deletes nothing.
        $this->givenLoadedRating(42);

        $this->ratingResource->expects($this->never())->method('delete');
        $this->ratingResource->expects($this->never())->method('save');

        $result = $this->execute(['Quality' => ['remove' => true]], true);

        $this->assertSame(1, $result->getRemoved());
        $this->assertTrue($result->isSuccessful());
    }

    public function testFullExportDumpsEveryProductRating(): void
    {
        $rating = $this->getMockBuilder(Rating::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getData'])
            ->addMethods(['getRatingCode'])
            ->getMock();
        $rating->method('getRatingCode')->willReturn('Quality');
        $rating->method('getId')->willReturn(7);
        $rating->method('getData')->willReturnMap([
            ['is_active', null, 1],
            ['position', null, 3],
        ]);

        $collection = $this->createMock(RatingCollection::class);
        $collection->expects($this->once())->method('addEntityFilter')->with(1)->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$rating]));

        $source = $this->getMockBuilder(Rating::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollection'])
            ->getMock();
        $source->method('getCollection')->willReturn($collection);
        $this->ratingFactory->method('create')->willReturn($source);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $this->storeRepository->method('getById')->with(5)->willReturn($store);
        $this->ratingResource->method('getStores')->with(7)->willReturn([5]);

        $out = $this->component->export(new ExportContext([], true));

        $this->assertSame(
            ['review_rating' => ['Quality' => ['is_active' => 1, 'position' => 3, 'stores' => ['default']]]],
            $out
        );
    }

    public function testRefreshOnlyRewritesTrackedRatings(): void
    {
        // Tracked rating that still exists -> its values get refreshed; the 'version'
        // key is preserved while is_active/position/stores are overwritten from DB.
        $rating = $this->createMock(Rating::class);
        $rating->method('getId')->willReturn(7);
        $rating->method('getData')->willReturnMap([
            ['is_active', null, 0],
            ['position', null, 2],
        ]);
        $rating->method('load')->willReturnSelf();
        $this->ratingFactory->method('create')->willReturn($rating);

        $this->ratingResource->method('getStores')->with(7)->willReturn([]);

        $existing = ['review_rating' => ['Quality' => ['version' => 4, 'is_active' => 9, 'position' => 9]]];
        $out = $this->component->export(new ExportContext($existing, false));

        $this->assertSame(
            ['review_rating' => ['Quality' => ['version' => 4, 'is_active' => 0, 'position' => 2, 'stores' => []]]],
            $out
        );
    }

    public function testRefreshKeepsUntrackedEntryForMissingRating(): void
    {
        // Tracked code no longer in the DB -> the original entry is left untouched.
        $rating = $this->createMock(Rating::class);
        $rating->method('getId')->willReturn(null);
        $rating->method('load')->willReturnSelf();
        $this->ratingFactory->method('create')->willReturn($rating);

        $existing = ['review_rating' => ['Gone' => ['is_active' => 1, 'position' => 5]]];
        $out = $this->component->export(new ExportContext($existing, false));

        $this->assertSame($existing, $out);
    }

    /**
     * @param array $reviewRatings value of the `review_rating` node (keyed by code)
     * @param array|null $rawData full source override (bypasses $reviewRatings)
     */
    private function execute(
        array $reviewRatings,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['review_rating' => $reviewRatings];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * Stub ratingFactory->create() to return a Rating whose load() yields a model
     * reporting the given id (0 = not yet persisted).
     */
    private function givenLoadedRating(int $id): Rating&MockObject
    {
        $rating = $this->getMockBuilder(Rating::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getOptions', 'getId', 'setData', 'setEntityId'])
            ->addMethods(['setRatingCode', 'setStores', 'getRatingCode'])
            ->getMock();
        $rating->method('load')->willReturnSelf();
        if ($id > 0) {
            $rating->method('getId')->willReturn($id);
        } else {
            $rating->method('getId')->willReturn(0);
        }
        $this->ratingFactory->method('create')->willReturn($rating);

        return $rating;
    }
}
