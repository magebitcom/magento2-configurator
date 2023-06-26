<?php

namespace CtiDigital\Configurator\Component;

use CtiDigital\Configurator\Api\ComponentInterface;
use CtiDigital\Configurator\Api\LoggerInterface;
use CtiDigital\Configurator\Api\VersionManagementInterface;
use CtiDigital\Configurator\Exception\ComponentException;
use Exception;
use CtiDigital\Configurator\Model\Processor;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @see \CtiDigital\Configurator\Component\Pages
 */
class Pages implements ComponentInterface
{
    protected string $alias = 'pages';
    protected string $name = 'Pages';
    protected string $description = 'Component to create/maintain pages.';
    protected array $requiredFields = ['title'];
    protected array $defaultValues = ['page_layout' => 'empty', 'is_active' => '1'];

    protected $viewModelRegistry = null;

    /**
     * @param PageRepositoryInterface $pageRepository
     * @param PageInterfaceFactory $pageFactory
     * @param StoreRepositoryInterface $storeRepository
     * @param LoggerInterface $log
     * @param Filesystem $filesystem
     * @param Escaper $escaper
     * @param VersionManagementInterface $versionManagement
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly PageRepositoryInterface    $pageRepository,
        private readonly PageInterfaceFactory       $pageFactory,
        private readonly StoreRepositoryInterface   $storeRepository,
        private readonly LoggerInterface            $log,
        private readonly Filesystem                 $filesystem,
        private readonly Escaper $escaper,
        private readonly VersionManagementInterface $versionManagement,
        private readonly ObjectManagerInterface $objectManager
    ) {
        if (class_exists('Hyva\Theme\Model\ViewModelRegistry')) {
            $this->viewModelRegistry = $this->objectManager->create('Hyva\Theme\Model\ViewModelRegistry');
        }
    }

    /**
     * Loop through the data array and process page data
     *
     * @param null $data
     * @param string $mode
     * @return void
     * @throws LocalizedException
     */
    public function execute($data = null, string $mode = Processor::MODE_MAINTAIN): void
    {
        try {
            foreach ($data as $identifier => $data) {
                $this->processPage($identifier, $data, $mode);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Create or update page data
     *
     * @param string $identifier
     * @param array $data
     * @param string $mode
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD)
     */
    protected function processPage(string $identifier, array $data, string $mode): void
    {
        try {
            foreach ($data['page'] as $pageData) {
                if (isset($pageData['stores'])) {
                    foreach ($pageData['stores'] as $storeCode) {
                        $store = $this->storeRepository->get($storeCode);
                        $pageId = $this->pageFactory->create()->checkIdentifier($identifier, $store->getId());
                    }
                } else {
                    $pageId = $this->pageFactory->create()->checkIdentifier($identifier, 0);
                }

                $version = $pageData['version'] ?? null;
                $versionId = $this->alias . '_' . $identifier;

                if (isset($pageData['stores'])) {
                    $versionId .= implode('_', $pageData['stores']);
                }

                if ($version) {
                    unset($pageData['version']);
                }

                /** @var PageInterface $page */
                if ($pageId) {
                    $isNewVersion = $version && $this->versionManagement->isNewVersion($versionId, (int) $version);
                    if ($mode === 'create' && !$isNewVersion) {
                        continue;
                    }
                    $page = $this->pageRepository->getById($pageId);
                } else {
                    $page = $this->pageFactory->create();
                    $page->setIdentifier($identifier);
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
                    $this->pageRepository->save($page);
                    $this->log->logInfo(sprintf(
                        "Save page %s",
                        $identifier . ' (' . $page->getId() . ')'
                    ));

                }

                if ($version) {
                    $this->versionManagement->setVersion($versionId, (int) $version);
                }
            }
        } catch (NoSuchEntityException $e) {
            $this->log->logError($e->getMessage());
        }
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
                throw new ComponentException('Required Data Missing ' . $key);
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
