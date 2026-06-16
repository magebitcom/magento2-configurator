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
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
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
class Categories implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'categories';
    private const DESCRIPTION = 'Component to import categories.';

    /** Fields exported per category (name is emitted first, separately). */
    private const EXPORT_FIELDS = [
        'is_active',
        'position',
        'include_in_menu',
        'description',
        'page_layout',
        'custom_use_parent_settings',
        'url_key',
        'display_mode',
        'is_anchor',
        'meta_title',
        'meta_keywords',
        'meta_description',
    ];

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
        private readonly CategoryResource $categoryResource,
        private readonly ReconciliationGate $gate
    ) {
    }

    /**
     * @throws FileSystemException
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();
        $mode = $context->getMode();

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
     * @param ComponentMode $mode
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     * @throws FileSystemException
     * @SuppressWarnings(PHPMD)
     */
    public function createOrUpdateCategory(
        Category $parentCategory,
        array $categories,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        foreach ($categories as $categoryValues) {
            // Load the category using its name and parent category
            /**
             * @var $category Category
             */
            $category = $this->category->create()->getCollection()
                ->addAttributeToSelect('*')
                ->addFieldToFilter('name', $categoryValues['name'])
                ->addFieldToFilter('parent_id', $parentCategory->getId())
                ->setPageSize(1)
                ->getFirstItem();

            $exists = (bool) $category->getId();

            // Explicit removal: `remove: true` deletes the category if it exists,
            // in either mode (not routed through the gate). Idempotent — a category
            // already absent is skipped. Note: deleting a category also deletes its
            // entire subtree, which is the expected behaviour for an explicit remove.
            if (!empty($categoryValues['remove'])) {
                $this->removeCategory($categoryValues['name'], $exists ? $category : false, $dryRun, $result);
                continue;
            }

            // Per-entity diff: an existing category whose tracked fields already match
            // the DB is left untouched. This avoids the no-op re-save that would
            // otherwise regenerate URL rewrites and throw UrlAlreadyExistsException.
            $unchanged = $exists && $this->isCategoryUnchanged($category, $categoryValues);

            $version = $categoryValues['version'] ?? null;
            $request = new ReconciliationRequest(
                self::ALIAS,
                $parentCategory->getId() . '_' . $categoryValues['name'],
                $mode,
                $exists,
                $version ? (int) $version : null,
                $exists ? $unchanged : null
            );

            if ($this->gate->decide($request)->isSkip()) {
                $this->log->logComment(
                    sprintf("Skip category '%s' modification (unchanged or create mode)", $categoryValues['name'])
                );
                // An unchanged category already matches the declared version; persist it
                // so a later manual edit isn't mistaken for a stale entity and overwritten.
                // The create-mode-protection skip ($unchanged === false) deliberately does
                // not, since YAML was not applied.
                if ($unchanged) {
                    $this->gate->commitVersion($request, $dryRun);
                }
                $result->recordSkipped();
                continue;
            }

            foreach ($categoryValues as $attribute => $value) {
                switch ($attribute) {
                    case in_array($attribute, $this->mainAttributes):
                        $category->setData($attribute, $value);
                        break;
                    case 'category':
                    case 'remove':
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

            $this->gate->commitVersion($request, $dryRun);

            if (isset($categoryValues['categories'])) {
                $this->createOrUpdateCategory($category, $categoryValues['categories'], $mode, $dryRun, $result);
            }
        }
    }

    /**
     * Compare the source-tracked fields against the loaded category to decide
     * whether anything would actually change. Mirrors the value resolution done
     * by createOrUpdateCategory() but performs no side effects (no image copy,
     * no save), so an unchanged category can be skipped entirely.
     *
     * Control keys (version, remove, categories) are ignored. When `is_active`
     * is omitted from the source the writer defaults it to active, so the same
     * default is asserted here.
     *
     * @param Category $category
     * @param array $categoryValues
     * @return bool
     */
    private function isCategoryUnchanged(Category $category, array $categoryValues): bool
    {
        foreach ($categoryValues as $attribute => $value) {
            switch (true) {
                case $attribute === 'version':
                case $attribute === 'remove':
                case $attribute === 'category':
                case $attribute === 'categories':
                    break;
                case $attribute === 'image':
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction
                    if (basename((string) $value) !== (string) $category->getData('image')) {
                        return false;
                    }
                    break;
                case $attribute === 'landing_page':
                    $block = $this->blockFactory->create()->setStoreId($category->getStoreId());
                    $this->blockResource->load($block, $value, 'identifier');
                    if ($block->getIdentifier()
                        && (string) $block->getId() !== (string) $category->getData('landing_page')
                    ) {
                        return false;
                    }
                    break;
                default:
                    if ((string) $category->getData($attribute) !== (string) $value) {
                        return false;
                    }
            }
        }

        // The writer activates a category by default when `is_active` is unset.
        if (!isset($categoryValues['is_active']) && (string) $category->getData('is_active') !== '1') {
            return false;
        }

        return true;
    }

    /**
     * Delete a category flagged with `remove: true`. Idempotent: a category that is
     * already absent records a skip rather than an error. Honors dry-run. Deleting a
     * category also deletes its subtree, which is acceptable for an explicit remove.
     *
     * @param string $name
     * @param Category|false $category
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     */
    protected function removeCategory(
        string $name,
        Category|false $category,
        bool $dryRun,
        ComponentResult $result
    ): void {
        if (!$category) {
            $this->log->logComment(sprintf("Category '%s' not present, nothing to remove", $name));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove category %s', $name));
        } else {
            $this->categoryResource->delete($category);
            $this->log->logInfo(sprintf('Removed category %s', $name));
        }

        $result->recordRemoved();
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
     * Export the category tree(s) in the source format. Full mode dumps the whole
     * tree under each store group's root; refresh re-exports each tracked
     * top-level category as its FULL current subtree (so admin field changes and
     * new sub-categories are captured), preserving non-exported keys (image,
     * landing_page, version) from the tracked entry. Image/landing_page are not
     * reverse-resolved.
     */
    public function export(ExportContext $context): array
    {
        if ($context->isFullExport()) {
            return ['categories' => $this->exportAllGroups($context->getFilter())];
        }

        return ['categories' => $this->refreshTrackedGroups($context->getExistingData())];
    }

    /**
     * @return array
     */
    private function exportAllGroups(?string $filter): array
    {
        $out = [];
        foreach ($this->groupFactory->create()->getCollection() as $group) {
            $name = (string) $group->getName();
            if ($filter !== null && $filter !== '' && !str_starts_with($name, $filter)) {
                continue;
            }
            try {
                $root = $this->getDefaultCategory($name);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                continue;
            }
            if (!$root) {
                continue;
            }
            $out[] = ['store_group' => $name, 'categories' => $this->exportChildren((int) $root->getId())];
        }

        return $out;
    }

    /**
     * @param array $existing
     * @return array
     */
    private function refreshTrackedGroups(array $existing): array
    {
        $entries = (isset($existing['categories']) && is_array($existing['categories'])) ? $existing['categories'] : [];

        $out = [];
        foreach ($entries as $groupEntry) {
            if (!is_array($groupEntry)) {
                $out[] = $groupEntry;
                continue;
            }

            $groupName = $this->getStoreGroup($groupEntry);
            try {
                $root = $this->getDefaultCategory($groupName);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $out[] = $groupEntry;
                continue;
            }
            if (!$root) {
                $out[] = $groupEntry;
                continue;
            }

            $categories = [];
            foreach ($groupEntry['categories'] ?? [] as $tracked) {
                if (!is_array($tracked) || !isset($tracked['name'])) {
                    $categories[] = $tracked;
                    continue;
                }
                $categoryId = $this->findChildByName((string) $tracked['name'], (int) $root->getId());
                if ($categoryId === null) {
                    // Category no longer exists; keep the tracked entry untouched.
                    $categories[] = $tracked;
                    continue;
                }
                $categories[] = $this->buildCategoryEntry($this->category->create()->load($categoryId), $tracked);
            }

            $rebuilt = $groupEntry;
            $rebuilt['categories'] = $categories;
            $out[] = $rebuilt;
        }

        return $out;
    }

    /**
     * Build a category's full source entry (name + value fields + recursive
     * children). Keys from a tracked entry that aren't reverse-exported here
     * (image, landing_page, version, …) are preserved.
     *
     * @param Category $category
     * @param array $preserve
     * @return array
     */
    private function buildCategoryEntry(Category $category, array $preserve = []): array
    {
        $entry = ['name' => (string) $category->getName()];

        foreach (self::EXPORT_FIELDS as $field) {
            $value = $category->getData($field);
            if ($value === null || $value === '') {
                continue;
            }
            $entry[$field] = $value;
        }

        // Preserve tracked keys we don't reverse (image, landing_page, version, …).
        foreach ($preserve as $key => $value) {
            if ($key === 'name' || $key === 'categories'
                || array_key_exists($key, $entry)
                || in_array($key, self::EXPORT_FIELDS, true)
            ) {
                continue;
            }
            $entry[$key] = $value;
        }

        $children = $this->exportChildren((int) $category->getId());
        if ($children !== []) {
            $entry['categories'] = $children;
        }

        return $entry;
    }

    /**
     * @return array
     */
    private function exportChildren(int $parentId): array
    {
        $collection = $this->category->create()->getCollection()
            ->addAttributeToSelect('*')
            ->addFieldToFilter('parent_id', $parentId)
            ->setOrder('position', 'ASC');

        $out = [];
        foreach ($collection as $child) {
            $out[] = $this->buildCategoryEntry($child);
        }

        return $out;
    }

    private function findChildByName(string $name, int $parentId): ?int
    {
        $category = $this->category->create()->getCollection()
            ->addAttributeToSelect('name')
            ->addFieldToFilter('name', $name)
            ->addFieldToFilter('parent_id', $parentId)
            ->setPageSize(1)
            ->getFirstItem();

        return $category->getId() ? (int) $category->getId() : null;
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
