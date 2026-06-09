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
use Magento\Cms\Model\PageFactory as CmsPageFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;

/**
 * Manage Hyvä Commerce CMS page-builder content (the JSON draft/published
 * content attached to a CMS page) from configurator sources.
 *
 * This integrates with the optional, paid `Hyva_CmsMagento` module. To keep the
 * core configurator dependency-free it takes NO Hyvä type-hints — it guards on
 * the module being enabled and persists via the `hyva_commerce_cms_page` table
 * directly (which also avoids the JSON double-escaping the Hyvä model performs).
 * When the module is absent the component is a no-op (and di:compile is unaffected).
 */
class HyvaPages implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'hyva_pages';
    private const DESCRIPTION = 'Component to create/maintain Hyvä CMS page-builder content (requires Hyva_CmsMagento).';
    private const HYVA_MODULE = 'Hyva_CmsMagento';
    private const TABLE = 'hyva_commerce_cms_page';

    /**
     * Value-bearing source keys rebuilt from the DB on refresh (inline content
     * variants, their `*_source` external-file references, and the liveview flag).
     * Everything else in a tracked entry is a non-DB structural key (version,
     * create_version_history, version_name, …) and is preserved verbatim.
     */
    private const DB_KEYS = [
        'content',
        'content_source',
        'draft_content',
        'draft_content_source',
        'published_content',
        'published_content_source',
        'is_liveview_enabled',
    ];

    public function __construct(
        private readonly CmsPageFactory $cmsPageFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly DirectoryList $directoryList,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!is_array($data) || $data === []) {
            $result->addError('No "hyva_pages" data found in the source data.');
            return $result;
        }

        if (!$this->moduleManager->isEnabled(self::HYVA_MODULE)) {
            $this->log->logComment(sprintf('%s is not installed; skipping Hyvä CMS pages.', self::HYVA_MODULE));
            return $result;
        }

        foreach ($data as $identifier => $pageData) {
            try {
                $this->processHyvaPage((string) $identifier, (array) $pageData, $context, $result);
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
    private function processHyvaPage(
        string $identifier,
        array $data,
        ComponentContext $context,
        ComponentResult $result
    ): void {
        $cmsPageId = $this->findCmsPageId($identifier);
        if ($cmsPageId === null) {
            throw new ComponentException((string) __(
                'CMS page "%1" not found; create it (e.g. the pages component) before attaching Hyvä content.',
                $identifier
            ));
        }

        $content = $this->resolveContent($data);
        $isLiveview = isset($data['is_liveview_enabled']) ? (bool) $data['is_liveview_enabled'] : true;

        $existing = $this->loadExisting('cms_page_id', $cmsPageId);
        $exists = $existing !== false;
        $unchanged = $exists && $this->isUnchanged($existing, $content, $isLiveview);

        $version = $data['version'] ?? null;
        $request = new ReconciliationRequest(
            self::ALIAS,
            $identifier,
            $context->getMode(),
            $exists,
            $version ? (int) $version : null,
            $exists ? $unchanged : null
        );

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logComment(sprintf('Hyvä page "%s" skipped (unchanged or create mode).', $identifier));
            $result->recordSkipped();
            return;
        }

        if ($context->isDryRun()) {
            $this->log->logInfo(sprintf('[dry-run] Would %s Hyvä CMS page "%s".', $outcome->value, $identifier));
            $outcome->record($result);
            return;
        }

        $this->persist('cms_page_id', $cmsPageId, $existing, $content, $isLiveview);
        $this->log->logInfo(sprintf('Hyvä CMS page "%s" %s.', $identifier, $outcome->value));

        if (!empty($data['create_version_history'])) {
            $this->createVersionHistory('cms_page', $cmsPageId, $content['published'], $data);
        }

        $this->gate->commitVersion($request, $context->isDryRun());
        $outcome->record($result);
    }

    private function findCmsPageId(string $identifier): ?int
    {
        $page = $this->cmsPageFactory->create();
        // phpcs:ignore Magento2.Legacy.RestrictedCode
        $page->load($identifier, 'identifier');

        return $page->getId() ? (int) $page->getId() : null;
    }

    /**
     * Resolve draft + published content from the source: a shared `content`/
     * `content_source`, or separate `draft_content[_source]` / `published_content[_source]`.
     *
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

        // Validate but do NOT re-encode (the file already carries correct escaping).
        json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ComponentException(
                (string) __('Invalid JSON in "%1": %2', $filePath, json_last_error_msg())
            );
        }

        return $content;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function loadExisting(string $refColumn, int $refId): array|false
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where($refColumn . ' = ?', $refId);

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
     * Raw insert/update to preserve JSON escaping (the Hyvä model re-escapes).
     *
     * @param array<string, mixed>|false $existing
     * @param array{draft: ?string, published: ?string} $content
     */
    private function persist(string $refColumn, int $refId, array|false $existing, array $content, bool $isLiveview): void
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
            $refColumn => $refId,
            'is_liveview_enabled' => $liveview,
            'draft_content' => $content['draft'] ?? '',
            'published_content' => $content['published'] ?? '',
            'creation_time' => $now,
            'update_time' => $now,
        ]);
    }

    /**
     * Optionally record a Hyvä version-history entry. The Hyvä classes are
     * resolved lazily here (never at construction) so this stays di-safe.
     */
    private function createVersionHistory(string $entityType, int $refId, ?string $publishedContent, array $data): void
    {
        try {
            $factory = $this->objectManager->get(
                'Hyva\CmsMagento\Api\Data\PageVersionHistoryInterfaceFactory'
            );
            $repository = $this->objectManager->get(
                'Hyva\CmsMagento\Api\PageVersionHistoryRepositoryInterface'
            );

            $entry = $factory->create();
            $entry->setEntityType($entityType);
            $entry->setEntityRefId($refId);
            $entry->setVersionData((string) $publishedContent);
            $entry->setWasPublished(true);
            $entry->setPinned(false);
            $entry->setName($data['version_name'] ?? 'Created via Configurator');
            if (isset($data['version_emoji'])) {
                $entry->setEmoji($data['version_emoji']);
            }
            $repository->save($entry);
            $this->log->logComment('Created Hyvä version-history entry.', 1);
        } catch (\Throwable $e) {
            $this->log->logError(sprintf('Failed to create Hyvä version history: %s', $e->getMessage()));
        }
    }

    /**
     * Export current Hyvä CMS page-builder content into the source format. Refresh
     * mode rewrites only the CMS page identifiers already tracked in the source
     * file; full mode dumps every `hyva_commerce_cms_page` row (optionally filtered
     * by a CMS page identifier prefix). Returns [] when the Hyvä module is disabled,
     * mirroring execute()'s guard. There are no secrets to skip for this component.
     */
    public function export(ExportContext $context): array
    {
        if (!$this->moduleManager->isEnabled(self::HYVA_MODULE)) {
            $this->log->logComment(sprintf('%s is not installed; skipping Hyvä CMS pages export.', self::HYVA_MODULE));
            return [];
        }

        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->getFilter());
    }

    /**
     * Refresh each tracked identifier by emitting its FULL current state from the
     * DB (content + is_liveview_enabled), reusing the same row-to-entry builder as
     * the full export so an admin change to a previously-untracked field (e.g. the
     * liveview flag) is captured rather than silently dropped. Non-DB structural
     * keys (version, create_version_history, version_name, version_emoji) and any
     * `*_source` external-file references are preserved from the tracked entry.
     * Tracked identifiers that no longer resolve to a CMS page (or have no Hyvä
     * row), and identifiers not matching the filter, are kept untouched.
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

            $cmsPageId = $this->findCmsPageId($id);
            if ($cmsPageId === null) {
                $out[$id] = $entry;
                continue;
            }

            $row = $this->loadExisting('cms_page_id', $cmsPageId);
            if ($row === false) {
                $out[$id] = $entry;
                continue;
            }

            $out[$id] = $this->buildEntryFromRow($entry, $row);
        }

        return $out;
    }

    /**
     * Build a tracked entry's FULL current state from a Hyvä DB row.
     *
     * The DB-backed content and liveview flag are rebuilt from scratch via the same
     * shaping the full export uses (draft == published collapses to a single inline
     * `content`, otherwise separate `draft_content`/`published_content`). When the
     * tracked entry sources its content from external files (`*_source` keys), those
     * references are preserved and the corresponding inline content key is NOT
     * emitted (we cannot rewrite the external file from here). Non-DB structural
     * keys (version, create_version_history, …) are preserved from the entry.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildEntryFromRow(array $entry, array $row): array
    {
        $full = $this->shapeRow($row);

        // External-file content references cannot be rewritten here: keep the
        // `*_source` key and drop the inline content the full shaping would emit.
        if (array_key_exists('content_source', $entry)) {
            unset($full['content'], $full['draft_content'], $full['published_content']);
            $full['content_source'] = $entry['content_source'];
        } else {
            if (array_key_exists('draft_content_source', $entry)) {
                unset($full['content'], $full['draft_content']);
                $full['draft_content_source'] = $entry['draft_content_source'];
            }
            if (array_key_exists('published_content_source', $entry)) {
                unset($full['content'], $full['published_content']);
                $full['published_content_source'] = $entry['published_content_source'];
            }
        }

        // Preserve non-DB structural keys (version, create_version_history,
        // version_name, version_emoji, …) carried by the tracked entry.
        foreach ($entry as $key => $value) {
            if (in_array($key, self::DB_KEYS, true)) {
                continue;
            }
            $full[$key] = $value;
        }

        return $full;
    }

    /**
     * Dump every Hyvä CMS page row in the source format, keyed by CMS page
     * identifier. Optionally filtered by an identifier prefix.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $connection = $this->resourceConnection->getConnection();
        $hyvaTable = $this->resourceConnection->getTableName(self::TABLE);
        $cmsTable = $this->resourceConnection->getTableName('cms_page');

        $select = $connection->select()
            ->from(['h' => $hyvaTable], ['draft_content', 'published_content', 'is_liveview_enabled'])
            ->join(['c' => $cmsTable], 'c.page_id = h.cms_page_id', ['identifier'])
            ->where('h.cms_page_id IS NOT NULL');

        if ($filter !== null && $filter !== '') {
            $select->where('c.identifier LIKE ?', $filter . '%');
        }

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(string) $row['identifier']] = $this->shapeRow($row);
        }

        return $out;
    }

    /**
     * Shape a single Hyvä DB row into a source-format entry: the liveview flag plus
     * either a shared inline `content` (when draft == published) or separate
     * `draft_content`/`published_content`. Shared by full export and refresh so both
     * emit the same full field set for a given row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeRow(array $row): array
    {
        $draft = (string) ($row['draft_content'] ?? '');
        $published = (string) ($row['published_content'] ?? '');

        $entry = ['is_liveview_enabled' => (bool) (int) ($row['is_liveview_enabled'] ?? 0)];

        if ($draft === $published) {
            $entry['content'] = $published;
        } else {
            $entry['draft_content'] = $draft;
            $entry['published_content'] = $published;
        }

        return $entry;
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
