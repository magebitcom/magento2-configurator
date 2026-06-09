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
use Exception;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Symfony\Component\Filesystem\Filesystem;
use Magento\Framework\App\Area;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * @see \Magebit\Configurator\Component\Pages
 */
class Pages implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'pages';
    private const DESCRIPTION = 'Component to create/maintain pages.';

    /**
     * CMS page fields written back to source on export, in source-file order.
     * `content` is the rendered output of a `source` template on import, so when an
     * entry tracks `source` we never overwrite it with the stored content.
     */
    private const EXPORT_FIELDS = [
        'title',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'content_heading',
        'content',
        'sort_order',
        'layout_update_xml',
        'custom_theme',
        'custom_root_template',
        'custom_layout_update_xml',
        'custom_theme_from',
        'custom_theme_to',
        'page_layout',
        'is_active',
    ];

    protected array $requiredFields = ['title'];
    protected array $defaultValues = ['page_layout' => 'empty', 'is_active' => '1'];

    protected $viewModelRegistry = null;

    public function __construct(
        private readonly PageRepositoryInterface    $pageRepository,
        private readonly PageInterfaceFactory       $pageFactory,
        private readonly StoreRepositoryInterface   $storeRepository,
        private readonly LoggerInterface            $log,
        private readonly Filesystem                 $filesystem,
        private readonly Escaper $escaper,
        private readonly ReconciliationGate $gate,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly Emulation $emulation,
    ) {
        if (class_exists('Hyva\Theme\Model\ViewModelRegistry')) {
            $this->viewModelRegistry = $this->objectManager->create('Hyva\Theme\Model\ViewModelRegistry');
        }
    }

    /**
     * Loop through the data array and process page data
     *
     * @throws LocalizedException
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();
        $mode = $context->getMode();

        if (!is_array($data)) {
            $result->addError('No page data found in the source data.');
            return $result;
        }

        try {
            foreach ($data as $identifier => $pageData) {
                $this->processPage((string) $identifier, $pageData, $mode, $context->isDryRun(), $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * Create or update page data
     *
     * @param string $identifier
     * @param array $data
     * @param ComponentMode $mode
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD)
     */
    protected function processPage(
        string $identifier,
        array $data,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        try {
            foreach ($data['page'] as $pageData) {
                if (isset($pageData['stores'])) {
                    foreach ($pageData['stores'] as $storeCode) {
                        $store = $this->storeRepository->get($storeCode);
                        $pageId = $this->getPageIdByIdentifier($identifier, (int) $store->getId());
                    }
                } else {
                    $pageId = $this->getPageIdByIdentifier($identifier, 0);
                }

                $version = $pageData['version'] ?? null;

                // Version key preserves the legacy composition: identifier with the
                // store codes appended (no separator) when stores are specified.
                $versionKey = $identifier;
                if (isset($pageData['stores'])) {
                    $versionKey .= implode('_', $pageData['stores']);
                }

                if ($version) {
                    unset($pageData['version']);
                }

                $request = new ReconciliationRequest(
                    self::ALIAS,
                    $versionKey,
                    $mode,
                    (bool) $pageId,
                    $version ? (int) $version : null
                );

                /** @var PageInterface $page */
                if ($pageId) {
                    if ($this->gate->decide($request)->isSkip()) {
                        $result->recordSkipped();
                        continue;
                    }
                    $page = $this->pageRepository->getById($pageId);
                    $isNew = false;
                } else {
                    $page = $this->pageFactory->create();
                    $page->setIdentifier($identifier);
                    $isNew = true;
                }

                $this->checkRequiredFields($pageData);
                $this->setDefaultFields($pageData);

                // Render store-scoped content (blocks, view models, config) under the target
                // store so the saved content reflects that store rather than the admin default.
                $emulationStarted = false;
                if (!empty($pageData['stores']) && is_array($pageData['stores'])) {
                    $firstStore = $this->storeRepository->get((string) reset($pageData['stores']));
                    $this->emulation->startEnvironmentEmulation(
                        (int) $firstStore->getId(),
                        Area::AREA_FRONTEND,
                        true
                    );
                    $emulationStarted = true;
                }

                try {
                    // Loop through each attribute of the data array
                    foreach ($pageData as $key => $value) {
                        // Check if content is from a file source
                        if ($key == "source") {
                            $key = 'content';

                            $file = BP . '/' . $value;

                            if (!$this->filesystem->exists($file)) {
                                return;
                            }

                            // phpcs:disable
                            ob_start();

                            $dictionary = [
                                'escaper' => $this->escaper,
                                'viewModels' => $this->viewModelRegistry
                            ];

                            try {
                                extract($dictionary, EXTR_SKIP);
                                include $file;
                            } catch (Exception $exception) {
                                ob_end_clean();
                                throw $exception;
                            }

                            $value = ob_get_clean();
                            // phpcs:enable
                        }

                        // Skip stores
                        if ($key == "stores") {
                            continue;
                        }

                        // Log the old value if any
                        $this->log->logComment(sprintf(
                            "Checking page %s, key %s => %s",
                            $identifier . ' (' . $page->getId() . ')',
                            $key,
                            $page->getData($key)
                        ), 1);

                        // Check if there is a difference in value
                        if ($page->getData($key) != $value) {
                            $page->setData($key, $value);

                            $this->log->logInfo(sprintf(
                                "Set page %s, key %s => %s",
                                $identifier . ' (' . $page->getId() . ')',
                                $key,
                                $value
                            ), 1);
                        }
                    }
                } finally {
                    if ($emulationStarted) {
                        $this->emulation->stopEnvironmentEmulation();

                        // Reset the URL builder to clear base URLs cached during emulation.
                        $urlBuilder = $this->objectManager->get(UrlInterface::class);
                        if (method_exists($urlBuilder, '_resetState')) {
                            $urlBuilder->_resetState();
                        }
                    }
                }

                // Process stores
                $page->setStores([0]);
                if (isset($pageData['stores'])) {
                    $page->unsetData('store_id');
                    $page->unsetData('store_data');

                    $stores = [];
                    foreach ($pageData['stores'] as $code) {
                        $stores[] = $store = $this->storeRepository->get($code)->getId();
                    }

                    $page->setStores($stores);
                }

                //we only need to save if the model has changed
                if ($page->hasDataChanges()) {
                    if ($dryRun) {
                        $this->log->logInfo(sprintf(
                            "[dry-run] Would %s page %s",
                            $isNew ? 'create' : 'save',
                            $identifier
                        ));
                    } else {
                        $this->pageRepository->save($page);
                        $this->log->logInfo(sprintf(
                            "Save page %s",
                            $identifier . ' (' . $page->getId() . ')'
                        ));
                    }

                    $isNew ? $result->recordCreated() : $result->recordUpdated();
                }

                $this->gate->commitVersion($request, $dryRun);
            }
        } catch (NoSuchEntityException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Get page ID by identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return false|int
     * @throws Exception
     */
    protected function getPageIdByIdentifier(string $identifier, int $storeId): false|int
    {
        try {
            $entityMetadata = $this->metadataPool->getMetadata(PageInterface::class);
        } catch (Exception $e) {
            $this->log->logError('Failed to get PageInterface entity metadata');
            throw $e;
        }
        $identifierField = $entityMetadata->getIdentifierField();
        $linkField = $entityMetadata->getLinkField();

        $stores = [Store::DEFAULT_STORE_ID, $storeId];
        $stores = array_unique($stores);
        $connection = $this->resourceConnection->getConnection();
        $cmsPageTable = $connection->getTableName('cms_page');
        $cmsPageStoreTable = $connection->getTableName('cms_page_store');
        $select = $connection->select()
            ->from(['cp' => $cmsPageTable], [$identifierField])
            ->join(
                ['cps' => $cmsPageStoreTable],
                'cp.' . $linkField . ' = cps.' . $linkField,
                []
            )
            ->where('cp.identifier = ?', $identifier)
            ->where('cps.store_id IN (?)', $stores)
            ->order('cps.store_id DESC')
            ->limit(1);

        $pageId = $connection->fetchOne($select);
        if (!$pageId) {
            return false;
        }

        return (int) $pageId;
    }

    /**
     * Check the required fields are set
     * @param $pageData
     * @throws ComponentException
     */
    protected function checkRequiredFields($pageData): void
    {
        foreach ($this->requiredFields as $key) {
            if (!array_key_exists($key, $pageData)) {
                throw new ComponentException((string) __('Required Data Missing %1', $key));
            }
        }
    }

    /**
     * Add default page data if fields not set
     * @param $pageData
     */
    protected function setDefaultFields(&$pageData): void
    {
        foreach ($this->defaultValues as $key => $value) {
            if (!array_key_exists($key, $pageData)) {
                $pageData[$key] = $value;
            }
        }
    }

    /**
     * Export current CMS pages into the source format. Refresh mode rewrites only
     * the identifiers already tracked in the source file (re-reading each tracked
     * page definition from `cms_page`); full mode dumps every CMS page (optionally
     * filtered by an identifier prefix). Entries whose content comes from a `source`
     * template keep their `source` reference rather than inlining stored content,
     * and any non-value keys (e.g. version, stores) are preserved. There are no
     * secrets to skip for this component.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->getFilter());
    }

    /**
     * Refresh each tracked identifier's page definitions from the DB, preserving the
     * tracked field set, the `source` reference, `stores`, and any non-value keys
     * (e.g. version). Tracked identifiers that no longer resolve to a CMS page, and
     * identifiers not matching the filter, are kept untouched.
     *
     * @param array $existing
     * @param string|null $filter
     * @return array
     */
    private function refreshTracked(array $existing, ?string $filter): array
    {
        $out = [];
        foreach ($existing as $identifier => $entry) {
            $id = (string) $identifier;
            $entry = (array) $entry;

            if ($filter !== null && $filter !== '' && !str_starts_with($id, $filter)) {
                $out[$id] = $entry;
                continue;
            }

            if (!isset($entry['page']) || !is_array($entry['page'])) {
                $out[$id] = $entry;
                continue;
            }

            $pages = [];
            foreach ($entry['page'] as $pageData) {
                $pages[] = $this->refreshPageEntry($id, (array) $pageData);
            }
            $entry['page'] = $pages;
            $out[$id] = $entry;
        }

        return $out;
    }

    /**
     * Re-read a single tracked page definition from the DB, updating only the keys
     * already present in the entry (preserving `source`, `stores`, `version`, …).
     * The entry is returned untouched when no matching CMS page exists.
     *
     * @param string $identifier
     * @param array $pageData
     * @return array
     */
    private function refreshPageEntry(string $identifier, array $pageData): array
    {
        $storeId = $this->resolveStoreId($pageData['stores'] ?? null);
        if ($storeId === null) {
            return $pageData;
        }

        try {
            $pageId = $this->getPageIdByIdentifier($identifier, $storeId);
        } catch (Exception $e) {
            $this->log->logError($e->getMessage());
            return $pageData;
        }

        if ($pageId === false) {
            return $pageData;
        }

        try {
            $page = $this->pageRepository->getById($pageId);
        } catch (LocalizedException $e) {
            $this->log->logError($e->getMessage());
            return $pageData;
        }

        foreach (self::EXPORT_FIELDS as $field) {
            // Only refresh keys the source already tracks; never overwrite a
            // `source` template by inlining the stored, rendered content.
            if (!array_key_exists($field, $pageData)) {
                continue;
            }
            if ($field === 'content' && array_key_exists('source', $pageData)) {
                continue;
            }
            $pageData[$field] = $page->getData($field);
        }

        return $pageData;
    }

    /**
     * Dump every CMS page in the source format, keyed by identifier, each with a
     * single `page` definition. Pages assigned to specific (non-default) store views
     * carry a `stores` list of store codes. Optionally filtered by identifier prefix.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $connection = $this->resourceConnection->getConnection();
        $cmsPageTable = $connection->getTableName('cms_page');

        $select = $connection->select()
            ->from(['cp' => $cmsPageTable], ['page_id', 'identifier']);

        if ($filter !== null && $filter !== '') {
            $select->where('cp.identifier LIKE ?', $filter . '%');
        }
        $select->order('cp.identifier ASC');

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $identifier = (string) $row['identifier'];
            $pageId = (int) $row['page_id'];

            try {
                $page = $this->pageRepository->getById($pageId);
            } catch (LocalizedException $e) {
                $this->log->logError($e->getMessage());
                continue;
            }

            $entry = [];
            foreach (self::EXPORT_FIELDS as $field) {
                $entry[$field] = $page->getData($field);
            }

            $stores = $this->resolveStoreCodes($pageId);
            if ($stores !== []) {
                $entry['stores'] = $stores;
            }

            $out[$identifier] = ['page' => [$entry]];
        }

        return $out;
    }

    /**
     * Resolve a store id to scope a tracked entry's lookup. Uses the first store
     * code when the entry targets specific stores, otherwise the default store (0).
     * Returns null when a tracked store code can no longer be resolved.
     *
     * @param mixed $stores
     * @return int|null
     */
    private function resolveStoreId(mixed $stores): ?int
    {
        if (!is_array($stores) || $stores === []) {
            return 0;
        }

        try {
            return (int) $this->storeRepository->get((string) reset($stores))->getId();
        } catch (NoSuchEntityException $e) {
            $this->log->logError($e->getMessage());
            return null;
        }
    }

    /**
     * Store codes a CMS page is assigned to, excluding the "all stores" (0) row.
     * Returns an empty array for default-scope pages so no `stores` key is emitted.
     *
     * @param int $pageId
     * @return string[]
     */
    private function resolveStoreCodes(int $pageId): array
    {
        try {
            $linkField = $this->metadataPool->getMetadata(PageInterface::class)->getLinkField();
        } catch (Exception $e) {
            $this->log->logError($e->getMessage());
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $cmsPageTable = $connection->getTableName('cms_page');
        $cmsPageStoreTable = $connection->getTableName('cms_page_store');

        $select = $connection->select()
            ->from(['cps' => $cmsPageStoreTable], ['store_id'])
            ->join(
                ['cp' => $cmsPageTable],
                'cp.' . $linkField . ' = cps.' . $linkField,
                []
            )
            ->where('cp.page_id = ?', $pageId);

        $codes = [];
        foreach ($connection->fetchCol($select) as $storeId) {
            if ((int) $storeId === Store::DEFAULT_STORE_ID) {
                continue;
            }
            try {
                $codes[] = $this->storeRepository->getById((int) $storeId)->getCode();
            } catch (NoSuchEntityException $e) {
                $this->log->logError($e->getMessage());
            }
        }

        return $codes;
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
