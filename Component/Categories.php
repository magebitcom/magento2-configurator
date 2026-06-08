<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\Processor;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Store\Model\Group;
use Magento\Store\Model\GroupFactory;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Categories implements ComponentInterface
{
    private const ALIAS = 'categories';
    private const DESCRIPTION = 'Component to import categories.';

    private array $mainAttributes = [
        'name',
        'is_active',
        'position',
        'include_in_menu',
        'description',
        'page_layout',
        'custom_use_parent_settings',
    ];

    public function __construct(
        private readonly CategoryFactory $category,
        private readonly GroupFactory $groupFactory,
        private readonly DirectoryList $dirList,
        private readonly LoggerInterface $log,
        private readonly BlockFactory $blockFactory,
        private readonly BlockResource $blockResource,
        private readonly CategoryResource $categoryResource
    ) {
    }

    /**
     * @throws FileSystemException
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();
        $mode = $context->getMode()->value;

        if (!isset($data['categories']) || !is_array($data['categories'])) {
            $result->addError('No "categories" node found in the source data.');
            return $result;
        }

        foreach ($data['categories'] as $store) {
            try {
                $group = $this->getStoreGroup($store);
                // Get the default category
                $category = $this->getDefaultCategory($group);
                if ($category === false) {
                    throw new ComponentException(
                        (string) __('No default category was found for the store group "%1"', $group)
                    );
                }
                if (isset($store['categories'])) {
                    $this->log->logInfo(sprintf('Updating categories for "%s"', $group));
                    $this->createOrUpdateCategory($category, $store['categories'], $mode, $context->isDryRun(), $result);
                }
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Gets the default category for the store group
     *
     * @param string|null $store
     * @return Category|bool
     */
    public function getDefaultCategory(?string $store = null): Category|bool
    {
        $groupCollection = $this->groupFactory->create()->getCollection()
            ->addFieldToFilter('name', $store);
        if ($groupCollection->getSize() === 1) {
            /**
             * @var $group Group
             */
            $group = $groupCollection->getFirstItem();
            return $this->category->create()->load($group->getRootCategoryId());
        }
        if ($groupCollection->getSize() > 1) {
            throw new ComponentException(
                (string) __('Multiple store groups were found with the name "%1"', $store)
            );
        }
        if ($groupCollection->getSize() === 0) {
            throw new ComponentException(
                (string) __('No store groups were found with the name "%1"', $store)
            );
        }
        return false;
    }

    /**
     * Creates/updates categories with the values in the YAML
     *
     * @param Category $parentCategory
     * @param array $categories
     * @param string $mode
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     * @throws FileSystemException
     * @SuppressWarnings(PHPMD)
     */
    public function createOrUpdateCategory(
        Category $parentCategory,
        array $categories,
        string $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        foreach ($categories as $categoryValues) {
            // Load the category using its name and parent category
            /**
             * @var $category Category
             */
            $category = $this->category->create()->getCollection()
                ->addFieldToFilter('name', $categoryValues['name'])
                ->addFieldToFilter('parent_id', $parentCategory->getId())
                ->setPageSize(1)
                ->getFirstItem();

            $exists = (bool) $category->getId();

            if ($exists && $mode === Processor::MODE_CREATE) {
                $this->log->logComment(sprintf("Skip category '%s' modification in create mode: ", $categoryValues['name']));
                $result->recordSkipped();
                continue;
            }

            foreach ($categoryValues as $attribute => $value) {
                switch ($attribute) {
                    case in_array($attribute, $this->mainAttributes):
                        $category->setData($attribute, $value);
                        break;
                    case 'category':
                        break;
                    case 'image':
                        // phpcs:ignore Magento2.Functions.DiscouragedFunction
                        $img = basename((string) $value);
                        // phpcs:ignore Magento2.Functions.DiscouragedFunction
                        $path = parse_url((string) $value);
                        $catMediaDir = $this->dirList->getPath('media') . '/' . 'catalog' . '/' . 'category' . '/';

                        if (!array_key_exists('host', $path)) {
                            $value = BP . '/' . trim((string) $value, '/');
                        }

                        // phpcs:ignore
                        if (!@copy($value, $catMediaDir . $img)) {
                            $this->log->logError('Failed to find image: ' . $value, 1);
                            break;
                        }

                        $category->setImage($img);
                        break;
                    case 'landing_page':
                        $block = $this->blockFactory->create()->setStoreId($category->getStoreId());
                        $this->blockResource->load($block, $value, 'identifier');

                        if (!$block->getIdentifier()) {
                            break;
                        }

                        $category->setData($attribute, $block->getId());
                        break;
                    default:
                        $category->setCustomAttribute($attribute, $value);
                }
            }

            // Set the category to be active
            if (!(isset($categoryValues['is_active']))) {
                $category->setIsActive(true);
            }

            // Get the path. If the category exists, then append the '/' to the end
            $path = $parentCategory->getPath();
            if ($category->getId()) {
                $path = $path . '/';
            }
            $category->setAttributeSetId($category->getResource()->getEntityType()->getDefaultAttributeSetId());
            $category->setPath($path);
            $category->setParentId($parentCategory->getId());
            // Update category in default scope
            $category->setStoreId(0);

            if ($dryRun) {
                $this->log->logInfo(
                    sprintf('[dry-run] Would %s category %s', $exists ? 'update' : 'create', $categoryValues['name'])
                );
                $exists ? $result->recordUpdated() : $result->recordCreated();
            } else {
                $this->categoryResource->save($category);

                $this->log->logInfo(
                    sprintf('Updated category %s', $category->getName()),
                    ($category->getLevel() - 1)
                );
                $exists ? $result->recordUpdated() : $result->recordCreated();
            }

            if (isset($categoryValues['categories'])) {
                $this->createOrUpdateCategory($category, $categoryValues['categories'], $mode, $dryRun, $result);
            }
        }
    }

    /**
     * @param array $data
     * @return string
     */
    private function getStoreGroup(array $data): string
    {
        if (isset($data['store_group']) === true) {
            return $data['store_group'];
        }
        return 'Main Website Store';
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
