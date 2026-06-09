<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model;

use Magebit\Configurator\Api\Data\ConfigInterface;
use Magebit\Configurator\Model\Config;
use Magebit\Configurator\Model\ConfigRepository;
use Magebit\Configurator\Model\ResourceModel\Config\ConfigCollection;
use Magebit\Configurator\Model\ResourceModel\Config\ConfigCollectionFactory;
use Magebit\Configurator\Model\ResourceModel\ConfigResource;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigRepositoryTest extends TestCase
{
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private ConfigCollectionFactory&MockObject $configCollectionFactory;
    private ConfigResource&MockObject $configResource;
    private CollectionProcessor&MockObject $collectionProcessor;
    private ConfigRepository $repository;

    protected function setUp(): void
    {
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->configCollectionFactory = $this->getMockBuilder(ConfigCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->configResource = $this->createMock(ConfigResource::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessor::class);

        $this->repository = new ConfigRepository(
            $this->searchCriteriaBuilder,
            $this->configCollectionFactory,
            $this->configResource,
            $this->collectionProcessor
        );
    }

    public function testGetCollectionBuildsAndProcessesCollection(): void
    {
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(ConfigCollection::class);

        $this->configCollectionFactory->expects($this->once())->method('create')->willReturn($collection);
        $this->collectionProcessor->expects($this->once())->method('process')->with($criteria, $collection);

        $this->assertSame($collection, $this->repository->getCollection($criteria));
    }

    public function testGetConfigFiltersByNameAndReturnsFirstItem(): void
    {
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $config = $this->createMock(ConfigInterface::class);
        $collection = $this->createMock(ConfigCollection::class);

        // Fluent builder: addFilter() chains, create() yields the criteria.
        $this->searchCriteriaBuilder->expects($this->once())
            ->method('addFilter')
            ->with(ConfigInterface::NAME, 'web/secure/base_url')
            ->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($criteria);

        $this->configCollectionFactory->method('create')->willReturn($collection);
        $this->collectionProcessor->expects($this->once())->method('process')->with($criteria, $collection);
        $collection->expects($this->once())->method('getFirstItem')->willReturn($config);

        $this->assertSame($config, $this->repository->getConfig('web/secure/base_url'));
    }

    public function testGetConfigReturnsNullWhenNoMatch(): void
    {
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(ConfigCollection::class);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($criteria);
        $this->configCollectionFactory->method('create')->willReturn($collection);
        $collection->method('getFirstItem')->willReturn(null);

        $this->assertNull($this->repository->getConfig('missing/path'));
    }

    public function testSaveDelegatesToResourceAndReturnsId(): void
    {
        // getId() comes from AbstractModel, not ConfigInterface, so mock the concrete model.
        $config = $this->createMock(Config::class);
        $config->method('getId')->willReturn(42);

        $this->configResource->expects($this->once())->method('save')->with($config);

        $this->assertSame(42, $this->repository->save($config));
    }

    public function testSaveCastsNullIdToZero(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getId')->willReturn(null);

        $this->configResource->expects($this->once())->method('save')->with($config);

        $this->assertSame(0, $this->repository->save($config));
    }

    public function testSavePropagatesAlreadyExistsException(): void
    {
        // ConfigResource::save() type-hints AbstractModel; Config satisfies both that and ConfigInterface.
        $config = $this->createMock(Config::class);

        $this->configResource->expects($this->once())
            ->method('save')
            ->with($config)
            ->willThrowException(new AlreadyExistsException(__('duplicate')));

        $this->expectException(AlreadyExistsException::class);
        $this->repository->save($config);
    }

    public function testDeleteDelegatesToResource(): void
    {
        // ConfigResource::delete() type-hints AbstractModel; Config satisfies both that and ConfigInterface.
        $config = $this->createMock(Config::class);

        $this->configResource->expects($this->once())->method('delete')->with($config);

        $this->repository->delete($config);
    }

    public function testDeletePropagatesException(): void
    {
        // ConfigResource::delete() type-hints AbstractModel; Config satisfies both that and ConfigInterface.
        $config = $this->createMock(Config::class);

        $this->configResource->expects($this->once())
            ->method('delete')
            ->with($config)
            ->willThrowException(new \Exception('cannot delete'));

        $this->expectException(\Exception::class);
        $this->repository->delete($config);
    }
}
