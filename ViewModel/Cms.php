<?php

declare(strict_types=1);

namespace CtiDigital\Configurator\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

class Cms implements ArgumentInterface
{
    /**
     * @param PageFactory $pageFactory
     * @param PageResource $pageResource
     * @param BlockFactory $blockFactory
     * @param BlockResource $blockResource
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly PageResource $pageResource,
        private readonly BlockFactory $blockFactory,
        private readonly BlockResource $blockResource,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Load page model by identifier
     *
     * @param string $identifier
     * @param int $store
     * @param string|null $storeCode
     * @return Page
     */
    public function getPage(string $identifier, int $store = 0, ?string $storeCode = null): Page
    {
        $page = $this->pageFactory->create();

        if ($storeCode) {
            $store = (int)$this->storeManager->getStore($storeCode)->getId();
        }

        $page->setStoreId($store);

        $this->pageResource->load($page, $identifier, 'identifier');

        if (!$page->getIdentifier()) {
            $page->setIdentifier($identifier);
        }

        return $page;
    }

    /**
     * Load block model by identifier
     *
     * @param string $identifier
     * @param int $store
     * @param string|null $storeCode
     * @return Block
     */
    public function getBlock(string $identifier, int $store = 0, ?string $storeCode = null): Block
    {
        $block = $this->blockFactory->create();

        if ($storeCode) {
            $store = (int)$this->storeManager->getStore($storeCode)->getId();
        }

        $block->setStoreId($store);

        $this->blockResource->load($block, $identifier, 'identifier');

        if (!$block->getIdentifier()) {
            $block->setIdentifier($identifier);
        }

        return $block;
    }

    /**
     * Load page model by identifier
     *
     * @param string $urlPath
     * @param int $store
     * @param string|null $storeCode
     * @return Category
     */
    public function getCategory(string $urlPath, int $store = 0, ?string $storeCode = null): Category
    {
        try {
            if ($storeCode) {
                $store = (int)$this->storeManager->getStore($storeCode)->getId();
            }

            /** @var Category $category */
            $category = $this->categoryCollectionFactory->create()
                ->addFieldToFilter('url_key', $urlPath)
                ->setStoreId($store)
                ->getFirstItem();
        } catch (LocalizedException $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

        return $category;
    }
}
