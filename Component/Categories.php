<?php

namespace CtiDigital\Configurator\Component;

use CtiDigital\Configurator\Api\ComponentInterface;
use CtiDigital\Configurator\Api\LoggerInterface;
use CtiDigital\Configurator\Exception\ComponentException;
use CtiDigital\Configurator\Model\Processor;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
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
    protected string $alias = 'categories';
    protected string $name = 'Categories';
    protected string $description = 'Component to import categories.';

    /** @var GroupFactory */
    private GroupFactory $groupFactory;

    /** @var DirectoryList */
    private DirectoryList $dirList;

    /** @var CategoryFactory */
    private CategoryFactory $category;

    /** @var LoggerInterface */
    private LoggerInterface $log;

    /** @var BlockFactory */
    private BlockFactory $blockFactory;

    /** @var BlockResource */
    private BlockResource $blockResource;

    private array $mainAttributes = [
        'name',
        'is_active',
        'position',
        'include_in_menu',
        'description',
        'page_layout',
        'custom_use_parent_settings',
    ];

    /**
     * Categories constructor.
     * @param CategoryFactory $category
     * @param GroupFactory $groupFactory
     * @param DirectoryList $dirList
     * @param LoggerInterface $log
     * @param BlockFactory $blockFactory
     * @param BlockResource $blockResource
     */
    public function __construct(
        CategoryFactory $category,
        GroupFactory $groupFactory,
        DirectoryList $dirList,
        LoggerInterface $log,
        BlockFactory $blockFactory,
        BlockResource $blockResource
    ) {
        $this->category = $category;
        $this->groupFactory = $groupFactory;
        $this->dirList = $dirList;
        $this->log = $log;
        $this->blockFactory = $blockFactory;
        $this->blockResource = $blockResource;
    }

    /**
     * @param $data
     * @param string $mode
     * @return void
     * @throws FileSystemException
     */
    public function execute($data = null, string $mode = Processor::MODE_MAINTAIN): void
    {
        if (isset($data['categories'])) {
            foreach ($data['categories'] as $store) {
                try {
                    $group = $this->getStoreGroup($store);
                    // Get the default category
                    $category = $this->getDefaultCategory($group);
                    if ($category === false) {
                        throw new ComponentException(
                            sprintf('No default category was found for the store group "%s"', $group)
                        );
                    }
                    if (isset($store['categories'])) {
                        $this->log->logInfo(sprintf('Updating categories for "%s"', $group));
                        $this->createOrUpdateCategory($category, $store['categories'], $mode);
                    }
                } catch (ComponentException $e) {
                    $this->log->logError($e->getMessage());
                }
            }
        }
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
                sprintf('Multiple store groups were found with the name "%s"', $store)
            );
        }
        if ($groupCollection->getSize() === 0) {
            throw new ComponentException(
                sprintf('No store groups were found with the name "%s"', $store)
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
     * @return void
     * @throws FileSystemException
     * @SuppressWarnings(PHPMD)
     */
    public function createOrUpdateCategory(
        Category $parentCategory,
        array    $categories = [],
        string $mode = Processor::MODE_MAINTAIN
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

            if ($category->getId() && $mode === Processor::MODE_CREATE) {
                $this->log->logComment(sprintf("Skip category '%s' modification in create mode: ", $categoryValues['name']));
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
                        $img = basename($value);
                        // phpcs:ignore Magento2.Functions.DiscouragedFunction
                        $path = parse_url($value);
                        $catMediaDir = $this->dirList->getPath('media') . '/' . 'catalog' . '/' . 'category' . '/';

                        if (!array_key_exists('host', $path)) {
                            $value = BP . '/' . trim($value, '/');
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
            $category->save();

            $this->log->logInfo(
                sprintf('Updated category %s', $category->getName()),
                ($category->getLevel() - 1)
            );

            if (isset($categoryValues['categories'])) {
                $this->createOrUpdateCategory($category, $categoryValues['categories']);
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

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }
}
