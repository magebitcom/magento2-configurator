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
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Exception\ComponentException;
use Exception;
use Magebit\Configurator\Model\Processor;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use Symfony\Component\Filesystem\Filesystem;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * @see \Magebit\Configurator\Component\Pages
 */
class Pages implements ComponentInterface
{
    private const ALIAS = 'pages';
    private const DESCRIPTION = 'Component to create/maintain pages.';

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
        private readonly VersionManagementInterface $versionManagement,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
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
        $mode = $context->getMode()->value;

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
     * @param string $mode
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD)
     */
    protected function processPage(
        string $identifier,
        array $data,
        string $mode,
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
                $versionId = self::ALIAS . '_' . $identifier;

                if (isset($pageData['stores'])) {
                    $versionId .= implode('_', $pageData['stores']);
                }

                if ($version) {
                    unset($pageData['version']);
                }

                /** @var PageInterface $page */
                if ($pageId) {
                    $isNewVersion = $version && $this->versionManagement->isNewVersion($versionId, (int) $version);
                    if ($mode === Processor::MODE_CREATE && !$isNewVersion) {
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

                if ($version) {
                    if ($dryRun) {
                        $this->log->logInfo(sprintf(
                            "[dry-run] Would set version %d for %s",
                            (int) $version,
                            $versionId
                        ));
                    } else {
                        $this->versionManagement->setVersion($versionId, (int) $version);
                    }
                }
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
