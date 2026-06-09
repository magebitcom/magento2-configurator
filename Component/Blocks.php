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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Exception;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\ResourceModel\Block\Collection;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\Store;
use Symfony\Component\Filesystem\Filesystem;

class Blocks implements ComponentInterface, ExportableComponentInterface
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
        private readonly ReconciliationGate $gate,
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
        $mode = $context->getMode();

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
     * @param ComponentMode $mode
     * @param bool $dryRun
     * @param ComponentResult $result
     * @throws Exception
     * @SuppressWarnings(PHPMD)
     */
    private function processBlock(
        string $identifier,
        array $blockData,
        ComponentMode $mode,
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

                // Version key preserves the legacy composition: identifier with the
                // store codes appended (no separator) when stores are specified.
                $versionKey = $identifier;
                if (isset($data['stores'])) {
                    $versionKey .= implode('_', $data['stores']);
                }

                if ($version) {
                    unset($data['version']);
                }

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

                $request = new ReconciliationRequest(
                    self::ALIAS,
                    $versionKey,
                    $mode,
                    !$isNew,
                    $version ? (int) $version : null
                );

                // If there is still no block to play with, create a new block object.
                if ($block === null) {
                    $block = $this->blockFactory->create();
                    $block->setIdentifier($identifier);
                    $canSave = true;
                } elseif ($this->gate->decide($request)->isSkip()) {
                    // In create mode we skip modifying an existing block (unless its version bumped).
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

                $this->gate->commitVersion($request, $dryRun);
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

    /**
     * Export CMS blocks into the source format. Refresh mode rewrites only the
     * identifiers already tracked in the source file, re-reading their current
     * title/content/is_active from `cms_block` while preserving structural keys
     * (version, source, stores). Full mode dumps every block (optionally filtered
     * by an identifier prefix).
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->isDryRun());
    }

    /**
     * Rebuild the tracked structure, refreshing each definition's DB-backed
     * fields from the current `cms_block` row. Definitions whose block can no
     * longer be found in the DB are kept unchanged.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing, bool $dryRun): array
    {
        $out = [];
        foreach ($existing as $identifier => $blockData) {
            if (!is_array($blockData) || !isset($blockData['block']) || !is_array($blockData['block'])) {
                $out[$identifier] = $blockData;
                continue;
            }

            $definitions = [];
            foreach ($blockData['block'] as $definition) {
                $definitions[] = is_array($definition)
                    ? $this->refreshDefinition((string) $identifier, $definition, $dryRun)
                    : $definition;
            }

            $rebuilt = $blockData;
            $rebuilt['block'] = $definitions;
            $out[$identifier] = $rebuilt;
        }

        return $out;
    }

    /**
     * Refresh a single block definition's DB-backed values, preserving any other
     * keys (version, source, stores). When the entry uses a `source` template the
     * current DB content is written into that file (created if missing) rather
     * than inlined into the YAML.
     *
     * @param string $identifier
     * @param array $definition
     * @param bool $dryRun
     * @return array
     */
    private function refreshDefinition(string $identifier, array $definition, bool $dryRun): array
    {
        $stores = (isset($definition['stores']) && is_array($definition['stores'])) ? $definition['stores'] : [];
        $block = $this->loadBlock($identifier, $stores);
        if ($block === null) {
            return $definition;
        }

        $usesSource = isset($definition['source']) && (string) $definition['source'] !== '';
        if ($usesSource) {
            $this->writeSourceContent((string) $definition['source'], (string) $block->getContent(), $dryRun);
        }

        // A tracked block exports everything about it from the DB.
        $entry = [
            'title' => $block->getTitle(),
            'is_active' => (int) $block->getIsActive(),
        ];
        if (!$usesSource) {
            $entry['content'] = $block->getContent();
        }

        // Preserve the tracked entry's non-DB keys (source, version, …).
        foreach ($definition as $key => $value) {
            if (in_array($key, ['title', 'is_active', 'content', 'stores'], true)) {
                continue;
            }
            $entry[$key] = $value;
        }

        // Re-resolve store assignment from the DB so admin store changes are captured.
        $codes = $this->resolveStoreCodes($block->getStoreId());
        if ($codes !== []) {
            $entry['stores'] = $codes;
        }

        return $entry;
    }

    /**
     * Write content into a `source` file (path relative to the Magento base dir),
     * creating the directory/file if needed. No-op (logged) during a dry run.
     */
    private function writeSourceContent(string $source, string $content, bool $dryRun): void
    {
        $path = BP . '/' . ltrim($source, '/');

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would write source content to %s', $path));
            return;
        }

        $dir = dirname($path);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!is_dir($dir)) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            mkdir($dir, 0755, true);
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        file_put_contents($path, $content);
        $this->log->logInfo(sprintf('Wrote source content to %s', $path));
    }

    /**
     * Dump every CMS block into the source format, one entry per block grouped by
     * identifier. Content is exported inline. Store codes are resolved from the
     * block's store ids; default scope (store id 0) yields no `stores` key.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $collection = $this->blockFactory->create()->getCollection();
        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter('identifier', ['like' => $filter . '%']);
        }

        $out = [];
        foreach ($collection as $block) {
            $identifier = (string) $block->getIdentifier();
            $definition = [
                'title' => $block->getTitle(),
                'content' => $block->getContent(),
                'is_active' => (int) $block->getIsActive(),
            ];

            $codes = $this->resolveStoreCodes($block->getStoreId());
            if ($codes !== []) {
                $definition['stores'] = $codes;
            }

            $out[$identifier]['block'][] = $definition;
        }

        return $out;
    }

    /**
     * Load the single CMS block for an identifier, narrowing by the first store
     * code when stores are specified (mirroring getBlockToProcess()).
     *
     * @param string $identifier
     * @param array $stores
     * @return Block|null
     * @throws LocalizedException
     */
    private function loadBlock(string $identifier, array $stores): ?Block
    {
        if (count($stores) > 0) {
            $store = $this->getStoreByCode((string) $stores[0]);
            $blocks = $this->blockFactory->create()->getCollection()
                ->addStoreFilter($store, false)
                ->addFieldToFilter('identifier', $identifier);
        } else {
            $blocks = $this->blockFactory->create()->getCollection()
                ->addFieldToFilter('identifier', $identifier);
        }

        return $blocks->count() ? $blocks->getFirstItem() : null;
    }

    /**
     * Resolve a block's store ids to store codes, dropping the default scope
     * (store id 0), which is represented by the absence of a `stores` key.
     *
     * @param mixed $storeIds
     * @return array
     */
    private function resolveStoreCodes(mixed $storeIds): array
    {
        $codes = [];
        foreach ((array) $storeIds as $storeId) {
            if ((int) $storeId === Store::DEFAULT_STORE_ID) {
                continue;
            }
            $store = $this->storeManager->load((int) $storeId);
            if ($store->getId()) {
                $codes[] = (string) $store->getCode();
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
