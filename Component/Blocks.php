<?php

namespace CtiDigital\Configurator\Component;

use CtiDigital\Configurator\Api\ComponentInterface;
use CtiDigital\Configurator\Api\VersionManagementInterface;
use CtiDigital\Configurator\Exception\ComponentException;
use CtiDigital\Configurator\Api\LoggerInterface;
use Exception;
use Hyva\Theme\Model\ViewModelRegistry;
use CtiDigital\Configurator\Model\Processor;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\ResourceModel\Block\Collection;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Symfony\Component\Filesystem\Filesystem;

class Blocks implements ComponentInterface
{

    protected string $alias = 'blocks';
    protected string $name = 'Blocks';
    protected string $description = 'Component to create/maintain blocks.';

    /**
     * Blocks constructor.
     * @param BlockInterfaceFactory $blockFactory
     * @param Store $storeManager
     * @param LoggerInterface $log
     * @param Filesystem $filesystem
     * @param ViewModelRegistry $viewModelRegistry
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly Store $storeManager,
        private readonly LoggerInterface $log,
        private readonly Filesystem $filesystem,
        private readonly ViewModelRegistry $viewModelRegistry,
        private readonly Escaper $escaper,
        private readonly VersionManagementInterface $versionManagement
    ) {
    }

    /**
     * @param null $data
     * @param string $mode
     * @throws Exception
     */
    public function execute($data = null, string $mode = Processor::MODE_MAINTAIN): void
    {
        try {
            foreach ($data as $identifier => $data) {
                $this->processBlock($identifier, $data, $mode);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * @param string $identifier
     * @param array $blockData
     * @param string $mode
     * @throws Exception
     * @SuppressWarnings(PHPMD)
     */
    private function processBlock(string $identifier, array $blockData, string $mode = Processor::MODE_MAINTAIN): void
    {
        try {
            // Loop through the block data
            foreach ($blockData['block'] as $data) {
                $this->log->logComment(sprintf("Checking for existing blocks with identifier '%s'", $identifier));

                // Load a collection blocks
                $blocks = $this->blockFactory->create()->getCollection()->addFieldToFilter('identifier', $identifier);

                // Set initial vars
                $canSave = false;
                $block = null;

                $version = $data['version'] ?? null;
                $versionId = $this->alias . '_' . $identifier;

                if (isset($data['stores'])) {
                    $versionId .= implode('_', $data['stores']);
                }

                if ($version) {
                    unset($data['version']);
                }

                $isNewVersion = isset($version) && $this->versionManagement->isNewVersion($versionId, (int) $version);

                // Check if there are existing blocks
                if ($blocks->count()) {
                    $stores = [];

                    // Check if stores are specified
                    if (isset($data['stores'])) {
                        $stores = $data['stores'];
                    }

                    // Find the exact block to process
                    $block = $this->getBlockToProcess($identifier, $blocks, $stores);
                }

                // If there is still no block to play with, create a new block object.
                if ($block === null) {
                    $block = $this->blockFactory->create();
                    $block->setIdentifier($identifier);
                    $canSave = true;
                } elseif ($mode === Processor::MODE_CREATE && !$isNewVersion) {
                    // In create mode we skip modifying block
                    $this->log->logComment(sprintf("'%s' Block exists, skip modifying it (create mode)", $identifier));
                    continue;
                }

                // Loop through each attribute of the data array
                foreach ($data as $key => $value) {
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
                        "Checking block %s, key %s => %s",
                        $identifier . ' (' . $block->getId() . ')',
                        $key,
                        $block->getData($key)
                    ), 1);

                    // Check if there is a difference in value
                    if ($block->getData($key) != $value) {
                        // If there is, allow the block to be saved
                        $canSave = true;
                        $block->setData($key, $value);

                        $this->log->logInfo(sprintf(
                            "Set block %s, key %s => %s",
                            $identifier . ' (' . $block->getId() . ')',
                            $key,
                            $value
                        ), 1);
                    }
                }

                // Process stores
                // @todo compare stores to see if a save is required
                $block->setStoreId(0);
                if (isset($data['stores'])) {
                    $block->unsetData('store_id');
                    $block->unsetData('store_data');
                    $stores = [];
                    foreach ($data['stores'] as $code) {
                        $stores[] = $this->getStoreByCode($code)->getId();
                    }
                    $block->setStores($stores);
                }

                // If we can save the block
                if ($canSave) {
                    $block->save();
                    $this->log->logInfo(sprintf(
                        "Save block %s",
                        $identifier . ' (' . $block->getId() . ')'
                    ));
                }

                if ($version) {
                    $this->versionManagement->setVersion($versionId, (int) $version);
                }
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Find the block to process given the identifier, block collection and optionally stores
     *
     * @param String $identifier
     * @param Collection $blocks
     * @param array $stores
     * @return Block|null
     * @throws LocalizedException
     */
    private function getBlockToProcess(
        string     $identifier,
        Collection $blocks,
        array $stores = []
    ):? Block {
        // If there is only 1 block and stores hasn't been specified
        if ($blocks->count() == 1 && count($stores) == 0) {
            // Return that one block
            return $blocks->getFirstItem();
        }

        // If we do have stores specified
        if (count($stores) > 0) {
            // Use first store as filter to get the block ID.
            // Ideally, we would want to do something more intelligent here.
            $store = $this->getStoreByCode($stores[0]);
            $blocks = $this->blockFactory->create()->getCollection()
                ->addStoreFilter($store, false)
                ->addFieldToFilter('identifier', $identifier);

            // We should have no more than 1 block unless something funky is happening. Return the first block anyway.
            if ($blocks->count() >= 1) {
                return $blocks->getFirstItem();
            }
        }

        // In all other scenarios, return null as we can't find the block.
        return null;
    }

    /**
     * @param string $code
     * @return Store
     * @throws LocalizedException
     */
    private function getStoreByCode(string $code): Store
    {
        // Load the store object
        $store = $this->storeManager->load($code, 'code');

        // Check if we get back a store ID.
        if (!$store->getId()) {
            // If not, stop the process by throwing an exception
            throw new ComponentException(sprintf("No store with code '%s' found", $code));
        }

        return $store;
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
