<?php

declare(strict_types=1);

namespace CtiDigital\Configurator\Model;

use CtiDigital\Configurator\Api\ConfigRepositoryInterface;
use CtiDigital\Configurator\Api\Data\ConfigInterface;
use CtiDigital\Configurator\Model\ResourceModel\Config\ConfigCollection;
use CtiDigital\Configurator\Model\ResourceModel\Config\ConfigCollectionFactory;
use CtiDigital\Configurator\Model\ResourceModel\ConfigResource;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;

class ConfigRepository implements ConfigRepositoryInterface
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ConfigCollectionFactory $configCollectionFactory
     * @param ConfigResource $configResource
     * @param CollectionProcessor $collectionProcessor
     */
    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ConfigCollectionFactory $configCollectionFactory,
        private readonly ConfigResource $configResource,
        private readonly CollectionProcessor $collectionProcessor
    ) {
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return ConfigCollection
     */
    public function getCollection(SearchCriteriaInterface $searchCriteria): ConfigCollection
    {
        $collection = $this->configCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        return $collection;
    }

    /**
     * @param string $name
     * @return ConfigInterface|null
     */
    public function getConfig(string $name): ?ConfigInterface
    {
        $collection = $this->getCollection($this->searchCriteriaBuilder
            ->addFilter(ConfigInterface::NAME, $name)->create());

        return $collection->getFirstItem();
    }

    /**
     * @param ConfigInterface $config
     * @return int
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function save(ConfigInterface $config): int
    {
        $this->configResource->save($config);

        return (int) $config->getId();
    }

    /**
     * @param ConfigInterface $config
     * @return void
     * @throws \Exception
     */
    public function delete(ConfigInterface $config): void
    {
        $this->configResource->delete($config);
    }
}
