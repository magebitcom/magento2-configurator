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
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory as CmsBlockInterfaceFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Manage Hyvä Commerce CMS block-builder content (the JSON draft/published
 * content attached to a CMS block) from configurator sources.
 *
 * Optional integration with the paid `Hyva_CmsMagento` module. Takes no Hyvä
 * type-hints (di-safe): guards on the module, persists via the
 * `hyva_commerce_cms_block` table, and no-ops when the module is absent. Can
 * auto-create the backing CMS block.
 */
class HyvaBlocks implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'hyva_blocks';
    private const DESCRIPTION = 'Component to create/maintain Hyvä CMS block content (requires Hyva_CmsMagento).';
    private const HYVA_MODULE = 'Hyva_CmsMagento';
    private const TABLE = 'hyva_commerce_cms_block';

    public function __construct(
        private readonly BlockRepositoryInterface $cmsBlockRepository,
        private readonly CmsBlockInterfaceFactory $cmsBlockFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly DirectoryList $directoryList,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!is_array($data) || $data === []) {
            $result->addError('No "hyva_blocks" data found in the source data.');
            return $result;
        }

        if (!$this->moduleManager->isEnabled(self::HYVA_MODULE)) {
            $this->log->logComment(sprintf('%s is not installed; skipping Hyvä CMS blocks.', self::HYVA_MODULE));
            return $result;
        }

        foreach ($data as $identifier => $blockData) {
            try {
                $this->processHyvaBlock((string) $identifier, (array) $blockData, $context, $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @throws ComponentException
     */
    private function processHyvaBlock(
        string $identifier,
        array $data,
        ComponentContext $context,
        ComponentResult $result
    ): void {
        $storeIds = $this->normaliseStoreIds($data['store_ids'] ?? [0]);
        $dryRun = $context->isDryRun();

        $cmsBlockId = $this->findCmsBlockId($identifier);
        if ($cmsBlockId === null && empty($data['auto_create_block'])) {
            throw new ComponentException((string) __(
                'CMS block "%1" not found. Set "auto_create_block: true" to create it automatically.',
                $identifier
            ));
        }

        $content = $this->resolveContent($data);
        $isLiveview = isset($data['is_liveview_enabled']) ? (bool) $data['is_liveview_enabled'] : true;

        // Existence for the gate is the Hyvä content row (so we don't overwrite
        // existing Hyvä content in create mode), keyed by the resolved block id.
        $existing = $cmsBlockId !== null ? $this->loadExisting($cmsBlockId) : false;
        $exists = $existing !== false;
        // contentId can only be finalised once we know the block id, so the
        // unchanged check happens after the block (and its id) is resolved below.

        $version = $data['version'] ?? null;
        $request = new ReconciliationRequest(
            self::ALIAS,
            $identifier,
            $context->getMode(),
            $exists,
            $version ? (int) $version : null
        );

        if ($exists && $this->gate->decide($request)->isSkip()) {
            $this->log->logComment(sprintf('Hyvä block "%s" exists, skipped (create mode).', $identifier));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf(
                '[dry-run] Would %s Hyvä CMS block "%s".',
                $exists ? 'update' : 'create',
                $identifier
            ));
            $exists ? $result->recordUpdated() : $result->recordCreated();
            return;
        }

        // Create the backing CMS block if needed, then sync store associations.
        if ($cmsBlockId === null) {
            $cmsBlockId = $this->createCmsBlock($identifier, $data, $storeIds);
        } elseif ($storeIds !== []) {
            $this->setBlockStoreAssociations($cmsBlockId, $storeIds);
        }

        // The Hyvä JSON references the block id in `contentId`; keep it in sync.
        $content['draft'] = $content['draft'] !== null ? $this->updateContentId($content['draft'], $cmsBlockId) : null;
        $content['published'] = $content['published'] !== null
            ? $this->updateContentId($content['published'], $cmsBlockId)
            : null;

        $existing = $this->loadExisting($cmsBlockId);
        if ($existing !== false && $this->isUnchanged($existing, $content, $isLiveview)) {
            $this->log->logComment(sprintf('Hyvä block "%s" already up to date.', $identifier));
            $result->recordSkipped();
            return;
        }

        $this->persist($cmsBlockId, $existing, $content, $isLiveview);
        $this->gate->commitVersion($request, $dryRun);
        $this->log->logInfo(sprintf('Hyvä CMS block "%s" %s.', $identifier, $existing === false ? 'created' : 'updated'));
        $existing === false ? $result->recordCreated() : $result->recordUpdated();
    }

    /**
     * @param mixed $storeIds
     * @return int[]
     */
    private function normaliseStoreIds($storeIds): array
    {
        if (!is_array($storeIds)) {
            $storeIds = [$storeIds];
        }
        return array_map('intval', $storeIds);
    }

    private function findCmsBlockId(string $identifier): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $blockId = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('cms_block'), ['block_id'])
                ->where('identifier = ?', $identifier)
                ->limit(1)
        );

        return $blockId ? (int) $blockId : null;
    }

    /**
     * @param int[] $storeIds
     * @throws ComponentException
     */
    private function createCmsBlock(string $identifier, array $data, array $storeIds): int
    {
        $title = $data['block_title'] ?? ucfirst(str_replace(['-', '_'], ' ', $identifier));
        $isActive = isset($data['block_is_active']) ? (bool) $data['block_is_active'] : true;

        $block = $this->cmsBlockFactory->create(['data' => [
            'title' => $title,
            'identifier' => $identifier,
            'content' => '',
            'is_active' => $isActive,
            'store_id' => $storeIds === [] ? [0] : $storeIds,
        ]]);

        try {
            $blockId = (int) $this->cmsBlockRepository->save($block)->getId();
        } catch (\Exception $e) {
            throw new ComponentException((string) __('Failed to create CMS block "%1": %2', $identifier, $e->getMessage()));
        }

        $this->setBlockStoreAssociations($blockId, $storeIds === [] ? [0] : $storeIds);
        $this->log->logInfo(sprintf('Created CMS block "%s" (ID %d).', $identifier, $blockId), 1);

        return $blockId;
    }

    /**
     * Reconcile cms_block_store rows. The table keys on the entity link field
     * (`row_id` under staging, `block_id` otherwise).
     *
     * @param int[] $storeIds
     */
    private function setBlockStoreAssociations(int $blockId, array $storeIds): void
    {
        $connection = $this->resourceConnection->getConnection();
        $blockTable = $this->resourceConnection->getTableName('cms_block');
        $storeTable = $this->resourceConnection->getTableName('cms_block_store');

        $linkField = $connection->tableColumnExists($blockTable, 'row_id') ? 'row_id' : 'block_id';
        $linkId = (int) $connection->fetchOne(
            $connection->select()->from($blockTable, [$linkField])->where('block_id = ?', $blockId)->limit(1)
        );
        if ($linkId === 0) {
            return;
        }

        $existing = array_map('intval', $connection->fetchCol(
            $connection->select()->from($storeTable, ['store_id'])->where($linkField . ' = ?', $linkId)
        ));

        $remove = array_diff($existing, $storeIds);
        if ($remove !== []) {
            $connection->delete($storeTable, [$linkField . ' = ?' => $linkId, 'store_id IN (?)' => $remove]);
        }
        foreach (array_diff($storeIds, $existing) as $storeId) {
            $connection->insert($storeTable, [$linkField => $linkId, 'store_id' => (int) $storeId]);
        }
    }

    /**
     * @return array{draft: ?string, published: ?string}
     * @throws ComponentException
     */
    private function resolveContent(array $data): array
    {
        if (isset($data['content_source']) || isset($data['content'])) {
            $shared = isset($data['content_source'])
                ? $this->loadContentFromFile((string) $data['content_source'])
                : (string) $data['content'];
            return ['draft' => $shared, 'published' => $shared];
        }

        $draft = null;
        if (isset($data['draft_content_source'])) {
            $draft = $this->loadContentFromFile((string) $data['draft_content_source']);
        } elseif (isset($data['draft_content'])) {
            $draft = (string) $data['draft_content'];
        }

        $published = null;
        if (isset($data['published_content_source'])) {
            $published = $this->loadContentFromFile((string) $data['published_content_source']);
        } elseif (isset($data['published_content'])) {
            $published = (string) $data['published_content'];
        }

        return ['draft' => $draft, 'published' => $published];
    }

    /**
     * @throws ComponentException
     */
    private function loadContentFromFile(string $filePath): string
    {
        $fullPath = $this->directoryList->getRoot() . '/' . ltrim($filePath, '/');

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!is_file($fullPath)) {
            throw new ComponentException((string) __('Hyvä content file not found: %1', $filePath));
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new ComponentException((string) __('Failed to read Hyvä content file: %1', $filePath));
        }

        json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ComponentException(
                (string) __('Invalid JSON in "%1": %2', $filePath, json_last_error_msg())
            );
        }

        return $content;
    }

    /**
     * Keep the JSON `contentId` aligned with the actual block id.
     */
    private function updateContentId(string $content, int $blockId): string
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || !isset($decoded['contentId'])) {
            return $content;
        }
        $decoded['contentId'] = (string) $blockId;
        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $encoded === false ? $content : $encoded;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function loadExisting(int $cmsBlockId): array|false
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where('cms_block_id = ?', $cmsBlockId);

        return $connection->fetchRow($select);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{draft: ?string, published: ?string} $content
     */
    private function isUnchanged(array $existing, array $content, bool $isLiveview): bool
    {
        return (int) $existing['is_liveview_enabled'] === ($isLiveview ? 1 : 0)
            && ($content['draft'] === null || $existing['draft_content'] === $content['draft'])
            && ($content['published'] === null || $existing['published_content'] === $content['published']);
    }

    /**
     * @param array<string, mixed>|false $existing
     * @param array{draft: ?string, published: ?string} $content
     */
    private function persist(int $cmsBlockId, array|false $existing, array $content, bool $isLiveview): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $liveview = $isLiveview ? 1 : 0;
        $now = new \Zend_Db_Expr('NOW()');

        if ($existing !== false) {
            $bind = ['is_liveview_enabled' => $liveview, 'update_time' => $now];
            if ($content['draft'] !== null) {
                $bind['draft_content'] = $content['draft'];
            }
            if ($content['published'] !== null) {
                $bind['published_content'] = $content['published'];
            }
            $connection->update($table, $bind, [$connection->quoteIdentifier('id') . ' = ?' => $existing['id']]);
            return;
        }

        $connection->insert($table, [
            'cms_block_id' => $cmsBlockId,
            'is_liveview_enabled' => $liveview,
            'draft_content' => $content['draft'] ?? '',
            'published_content' => $content['published'] ?? '',
            'creation_time' => $now,
            'update_time' => $now,
        ]);
    }

    /**
     * Export current Hyvä CMS block content into the source format. Refresh mode
     * rewrites only the block identifiers already tracked in the source file;
     * full mode dumps every `hyva_commerce_cms_block` row joined to its CMS block
     * identifier (optionally filtered by an identifier prefix). No-ops to an empty
     * array when the optional Hyvä module is absent.
     */
    public function export(ExportContext $context): array
    {
        if (!$this->moduleManager->isEnabled(self::HYVA_MODULE)) {
            $this->log->logComment(sprintf('%s is not installed; skipping Hyvä CMS blocks export.', self::HYVA_MODULE));
            return [];
        }

        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->getFilter());
    }

    /**
     * Refresh each tracked block from the DB, preserving non-value keys (version,
     * auto_create_block, block_title, …). Identifiers that don't match the filter,
     * or no longer exist in the DB, are kept untouched.
     *
     * @param array $existing
     * @param string|null $filter
     * @return array
     */
    private function refreshTracked(array $existing, ?string $filter): array
    {
        $out = [];
        foreach ($existing as $identifier => $entry) {
            $entry = (array) $entry;

            if ($filter !== null && $filter !== '' && !str_starts_with((string) $identifier, $filter)) {
                $out[$identifier] = $entry;
                continue;
            }

            $cmsBlockId = $this->findCmsBlockId((string) $identifier);
            $row = $cmsBlockId !== null ? $this->loadExisting($cmsBlockId) : false;
            if ($cmsBlockId === null || $row === false) {
                $out[$identifier] = $entry;
                continue;
            }

            $out[$identifier] = $this->applyCurrentValues($entry, $row, $cmsBlockId);
        }

        return $out;
    }

    /**
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['h' => $this->resourceConnection->getTableName(self::TABLE)], ['cms_block_id'])
            ->join(
                ['b' => $this->resourceConnection->getTableName('cms_block')],
                'b.block_id = h.cms_block_id',
                ['identifier']
            );

        if ($filter !== null && $filter !== '') {
            $select->where('b.identifier LIKE ?', $filter . '%');
        }

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $identifier = (string) $row['identifier'];
            $cmsBlockId = (int) $row['cms_block_id'];
            $hyvaRow = $this->loadExisting($cmsBlockId);
            if ($hyvaRow === false) {
                continue;
            }
            $out[$identifier] = $this->applyCurrentValues([], $hyvaRow, $cmsBlockId);
        }

        return $out;
    }

    /**
     * Build/refresh a single entry's exported values from the Hyvä content row,
     * preserving any pre-existing non-value keys carried in from the source file.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function applyCurrentValues(array $entry, array $row, int $cmsBlockId): array
    {
        $draft = (string) ($row['draft_content'] ?? '');
        $published = (string) ($row['published_content'] ?? '');

        // Drop any input variants so the exported entry is unambiguous, then write
        // the current draft/published content back. When both sides are identical
        // we collapse to a single `content` key (mirrors execute()'s shared path).
        unset(
            $entry['content'],
            $entry['content_source'],
            $entry['draft_content'],
            $entry['draft_content_source'],
            $entry['published_content'],
            $entry['published_content_source']
        );

        if ($draft === $published) {
            $entry['content'] = $draft;
        } else {
            $entry['draft_content'] = $draft;
            $entry['published_content'] = $published;
        }

        $entry['is_liveview_enabled'] = (int) ($row['is_liveview_enabled'] ?? 0) === 1;
        $entry['store_ids'] = $this->loadBlockStoreIds($cmsBlockId);

        return $entry;
    }

    /**
     * Read the store associations for a CMS block from `cms_block_store`, keyed by
     * the entity link field (`row_id` under staging, `block_id` otherwise).
     *
     * @return int[]
     */
    private function loadBlockStoreIds(int $blockId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $blockTable = $this->resourceConnection->getTableName('cms_block');
        $storeTable = $this->resourceConnection->getTableName('cms_block_store');

        $linkField = $connection->tableColumnExists($blockTable, 'row_id') ? 'row_id' : 'block_id';
        $linkId = (int) $connection->fetchOne(
            $connection->select()->from($blockTable, [$linkField])->where('block_id = ?', $blockId)->limit(1)
        );
        if ($linkId === 0) {
            return [0];
        }

        $storeIds = array_map('intval', $connection->fetchCol(
            $connection->select()->from($storeTable, ['store_id'])->where($linkField . ' = ?', $linkId)
        ));
        sort($storeIds);

        return $storeIds === [] ? [0] : $storeIds;
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
