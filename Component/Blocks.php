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
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Exception;
use Magebit\Configurator\Model\Processor;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\ResourceModel\Block\Collection;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\Store;
use Symfony\Component\Filesystem\Filesystem;

class Blocks implements ComponentInterface
{
    private const ALIAS = 'blocks';
    private const DESCRIPTION = 'Component to create/maintain blocks.';

    protected $viewModelRegistry = null;

    public function __construct(
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly Store $storeManager,
        private readonly LoggerInterface $log,
        private readonly Filesystem $filesystem,
        private readonly Escaper $escaper,
        private readonly VersionManagementInterface $versionManagement,
        private readonly ObjectManagerInterface $objectManager
    ) {
        if (class_exists('Hyva\Theme\Model\ViewModelRegistry')) {
            $this->viewModelRegistry = $this->objectManager->create('Hyva\Theme\Model\ViewModelRegistry');
        }
    }

    /**
     * @throws Exception
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();
        $mode = $context->getMode()->value;

        if (!is_array($data)) {
            $result->addError('No block data found in the source data.');
            return $result;
        }

        try {
            foreach ($data as $identifier => $blockData) {
                $this->processBlock((string) $identifier, $blockData, $mode, $context->isDryRun(), $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * @param string $identifier
     * @param array $blockData
     * @param string $mode
     * @param bool $dryRun
     * @param ComponentResult $result
     * @throws Exception
     * @SuppressWarnings(PHPMD)
     */
    private function processBlock(
        string $identifier,
        array $blockData,
        string $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
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
                $versionId = self::ALIAS . '_' . $identifier;

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

                // Track whether we are creating a new block or updating an existing one
                $isNew = $block === null;

                // If there is still no block to play with, create a new block object.
                if ($block === null) {
                    $block = $this->blockFactory->create();
                    $block->setIdentifier($identifier);
                    $canSave = true;
                } elseif ($mode === Processor::MODE_CREATE && !$isNewVersion) {
                    // In create mode we skip modifying block
                    $this->log->logComment(sprintf("'%s' Block exists, skip modifying it (create mode)", $identifier));
                    $result->recordSkipped();
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
                    if ($dryRun) {
                        $this->log->logInfo(sprintf(
                            "[dry-run] Would %s block %s",
                            $isNew ? 'create' : 'save',
                            $identifier
                        ));
                    } else {
                        $this->blockRepository->save($block);
                        $this->log->logInfo(sprintf(
                            "Save block %s",
                            $identifier . ' (' . $block->getId() . ')'
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
            throw new ComponentException((string) __("No store with code '%1' found", $code));
        }

        return $store;
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
