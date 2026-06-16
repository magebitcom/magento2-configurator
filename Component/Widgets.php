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
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area as AppArea;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreFactory;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory;
use Magento\Widget\Model\ResourceModel\Widget\Instance as WidgetInstanceResource;
use Magento\Widget\Model\ResourceModel\Widget\Instance\Collection as WidgetCollection;
use Magento\Widget\Model\Widget\Instance;
use Magento\Widget\Model\Widget\InstanceFactory as WidgetInstanceFactory;

class Widgets implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'widgets';
    private const DESCRIPTION = 'Component to manage CMS Widgets';

    public function __construct(
        private readonly WidgetCollection $widgetCollection,
        private readonly WidgetInstanceFactory $widgetFactory,
        private readonly StoreFactory $storeFactory,
        private readonly ThemeCollectionFactory $themeCollection,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $log,
        private readonly AppState $appState,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly WidgetInstanceResource $widgetResource,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if ($data === [] || !is_array($data)) {
            $result->addError('No widgets found in the source data.');
            return $result;
        }

        try {
            foreach ($data as $widgetData) {
                $this->processWidget($widgetData, $context->getMode(), $context->isDryRun(), $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    public function processWidget(
        array $widgetData,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        try {
            // Capture the configured stores so block references resolve in the right scope.
            $stores = (isset($widgetData['stores']) && is_array($widgetData['stores']))
                ? $widgetData['stores']
                : null;

            $version = $widgetData['version'] ?? null;
            if ($version) {
                unset($widgetData['version']);
            }

            $widget = $this->findWidgetByInstanceTypeAndTitle($widgetData['instance_type'], $widgetData['title']);

            // Explicit removal: `remove: true` deletes the widget if it exists,
            // in either mode. Idempotent — a widget already absent is skipped.
            if (!empty($widgetData['remove'])) {
                $this->removeWidget($widgetData['title'], $widget, $dryRun, $result);
                return;
            }

            $request = new ReconciliationRequest(
                self::ALIAS,
                $widgetData['instance_type'] . '|' . $widgetData['title'],
                $mode,
                $widget !== null,
                $version ? (int) $version : null
            );

            $isNew = false;
            $canSave = false;
            if ($widget === null) {
                $isNew = true;
                $canSave = true;
                /**
                 * @var Instance $widget
                 */
                $widget = $this->widgetFactory->create();
            } elseif ($this->gate->decide($request)->isSkip()) {
                // In create mode an existing widget is left untouched (unless its version bumped).
                $this->log->logComment(
                    sprintf("Widget '%s' exists, skip modifying it (create mode)", $widgetData['title']),
                    1
                );
                $result->recordSkipped();
                return;
            }

            if (!$isNew) {
                // The collection item carries only the widget_instance columns; load the
                // full instance so page_groups (stored in a separate table) are present
                // for the diff below and are not dropped when the widget is saved.
                $this->widgetResource->load($widget, (int) $widget->getId());
            }

            foreach ($widgetData as $key => $value) {
                // Skip the control key; it is not a widget field.
                if ($key == "remove") {
                    continue;
                }

                // @todo handle stores
                // Comma separated
                if ($key == "stores") {
                    $key = "store_ids";
                    $value = $this->getCommaSeparatedStoreIds($value);
                }

                if ($key == "parameters") {
                    $key = "widget_parameters";
                    $value = $this->populateWidgetParameters($value, $stores);
                }

                if ($key == "theme") {
                    $key = "theme_id";
                    $value = $this->getThemeId($value);
                }

                if ($key == "page_groups" && is_array($value)) {
                    if ($this->savePageGroups($widget, $value)) {
                        $canSave = true;
                    }
                    continue;
                }

                if ($widget->getData($key) == $value) {
                    $this->log->logComment(sprintf("Widget %s = %s", $key, $value), 1);
                    continue;
                }

                $canSave = true;
                $widget->setData($key, $value);
                if (is_array($value)) {
                    $this->log->logInfo(sprintf("Widget %s = %s", $key, print_r($value, true)), 1);
                } else {
                    $this->log->logInfo(sprintf("Widget %s = %s", $key, $value), 1);
                }
            }

            if (!$canSave) {
                // Unchanged existing widget: persist its version so a later manual edit
                // isn't mistaken for a stale entity and overwritten. A new widget always
                // has $canSave = true, and the create-mode-protection skip returned earlier.
                $this->gate->commitVersion($request, $dryRun);
                $result->recordSkipped();
                return;
            }

            if ($dryRun) {
                $this->log->logInfo(
                    sprintf('[dry-run] Would save Widget %s', $widget->getTitle()),
                    1
                );
                $this->gate->commitVersion($request, $dryRun);
                $isNew ? $result->recordCreated() : $result->recordUpdated();
                return;
            }

            $this->appState->emulateAreaCode(
                AppArea::AREA_FRONTEND,
                function () use ($widget) {
                    $this->widgetResource->save($widget);
                }
            );

            $this->log->logInfo(sprintf("Saved Widget %s", $widget->getTitle()), 1);
            $this->gate->commitVersion($request, $dryRun);
            $isNew ? $result->recordCreated() : $result->recordUpdated();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }
    }

    /**
     * Delete a widget flagged with `remove: true`. Idempotent: a widget that is
     * already absent records a skip rather than an error. Honors dry-run.
     *
     * @param string $title
     * @param mixed $widget
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     */
    private function removeWidget(
        string $title,
        mixed $widget,
        bool $dryRun,
        ComponentResult $result
    ): void {
        if ($widget === null || !$widget->getId()) {
            $this->log->logComment(sprintf("Widget '%s' not present, nothing to remove", $title));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove widget %s', $title));
        } else {
            $this->widgetResource->delete($widget);
            $this->log->logInfo(sprintf('Removed widget %s', $title));
        }

        $result->recordRemoved();
    }

    /**
     * @param string $widgetInstanceType
     * @param string $widgetTitle
     * @throws ComponentException
     * @todo get this one to work instead of findWidgetByInstanceTypeAndTitle()
     */
    public function getWidgetByInstanceTypeAndTitle($widgetInstanceType, $widgetTitle): ?DataObject
    {
        // Clear any existing filters applied to the widget collection
        $this->widgetCollection->getSelect()->reset(\Zend_Db_Select::WHERE);
        $this->widgetCollection->removeAllItems();

        // Filter widget collection
        $widgets = $this->widgetCollection
            ->addFieldToFilter('instance_type', $widgetInstanceType)
            ->addFieldToFilter('title', $widgetTitle)
            ->load();
        // @todo add store filter

        // If we have more than 1, throw an exception for now. Needs store filter to drill down the widgets further
        // into a single widget.
        if ($widgets->count() > 1) {
            throw new ComponentException(
                (string) __('Application Error: Need to figure out how to handle same titled widgets')
            );
        }

        // If there are no widgets, then it is like it doesn't even exist.
        // Return null
        if ($widgets->count() < 1) {
            return null;
        }

        // Return the widget itself since it is a perfect match
        return $widgets->getFirstItem();
    }

    /**
     * @param string $widgetInstanceType
     * @param string $widgetTitle
     * @return mixed|null
     */
    public function findWidgetByInstanceTypeAndTitle($widgetInstanceType, $widgetTitle)
    {
        // Loop through the widget collection to find any matches.
        foreach ($this->widgetCollection as $widget) {
            if ($widget->getTitle() == $widgetTitle && $widget->getInstanceType() == $widgetInstanceType) {
                // Return the widget if there is a match
                return $widget;
            }
        }

        // If there are no widgets, then it is like it doesn't even exist.
        // Return null
        return null;
    }

    /**
     * @param string $themeCode
     * @throws ComponentException
     */
    public function getThemeId($themeCode): int
    {
        // Filter Theme Collection
        $collection = $this->themeCollection->create();
        $themes = $collection->addFilter('code', $themeCode);

        if ($themes->count() == 0) {
            throw new ComponentException(
                (string) __('Could not find any themes with the theme code %1', $themeCode)
            );
        }

        $theme = $themes->getFirstItem();

        return (int) $theme->getId();
    }

    /**
     * @param array $parameters
     * @param array|null $stores Store codes the widget is assigned to, used to scope block lookups.
     * @todo better support with parameters that reference IDs of objects
     */
    public function populateWidgetParameters(array $parameters, ?array $stores = null): string
    {
        // Process block_identifier if present
        $processedParameters = $this->processBlockIdentifiers($parameters, $stores);

        // Default property return
        return $this->serializer->serialize($processedParameters);
    }

    /**
     * Process block identifiers in widget parameters and convert them to block IDs
     *
     * Usage:
     *
     * ```yaml
     * - parameters:
     * -    block_identifier: <block_identifier> # e.g. venta-contact-us-faq
     * ```
     * @param array $parameters
     * @param array|null $stores Store codes used to scope the block lookup.
     */
    private function processBlockIdentifiers(array $parameters, ?array $stores = null): array
    {
        $processedParameters = $parameters;

        // Convert store codes to IDs once so block lookups can be scoped.
        $storeIds = null;
        if ($stores) {
            $storeIds = explode(',', $this->getCommaSeparatedStoreIds($stores));
        }

        foreach ($parameters as $key => $value) {
            if ($key === 'block_identifier' && is_string($value)) {
                try {
                    $blockId = $this->getBlockIdByIdentifier($value, $storeIds);
                    // Replace block_identifier with block_id for the widget
                    unset($processedParameters['block_identifier']);
                    $processedParameters['block_id'] = $blockId;

                    $this->log->logInfo(
                        sprintf("Resolved block identifier '%s' to block ID '%s'", $value, $blockId),
                        1
                    );
                } catch (ComponentException $e) {
                    $this->log->logError(
                        sprintf("Failed to resolve block identifier '%s': %s", $value, $e->getMessage())
                    );
                    throw $e;
                }
            }
        }

        return $processedParameters;
    }

    /**
     * Get CMS block ID by identifier
     *
     * @param string $identifier
     * @param array|null $storeIds Store IDs to scope the lookup; the first is used when provided.
     * @throws ComponentException
     */
    private function getBlockIdByIdentifier($identifier, ?array $storeIds = null): string
    {
        try {
            $this->criteriaBuilder->addFilter('identifier', $identifier);

            // Scope the lookup to the widget's store so the correct block is matched
            // when the same identifier exists in multiple stores.
            if (!empty($storeIds)) {
                $firstStoreId = reset($storeIds);
                $this->criteriaBuilder->addFilter('store_id', $firstStoreId, 'in');
                $this->log->logInfo(
                    sprintf('Looking for block "%s" in store ID: %s', $identifier, $firstStoreId),
                    1
                );
            }

            $searchCriteria = $this->criteriaBuilder->create();

            $blocks = $this->blockRepository->getList($searchCriteria);

            if ($blocks->getTotalCount() === 0) {
                throw new ComponentException(
                    (string) __('CMS Block with identifier "%1" not found', $identifier)
                );
            }

            if ($blocks->getTotalCount() > 1) {
                $this->log->logComment(
                    sprintf('Multiple CMS blocks found with identifier "%s", using the first one', $identifier),
                    1
                );
            }

            foreach ($blocks->getItems() as $block) {
                return (string) $block->getId();
            }

            throw new ComponentException(
                (string) __('No block found with identifier "%1"', $identifier)
            );
        } catch (\Exception $e) {
            throw new ComponentException(
                (string) __('Error retrieving CMS block with identifier "%1": %2', $identifier, $e->getMessage())
            );
        }
    }

    /**
     * @param array $stores
     * @throws ComponentException
     */
    public function getCommaSeparatedStoreIds($stores): string
    {
        $storeIds = [];
        foreach ($stores as $code) {
            $storeView = $this->storeFactory->create();
            $storeView->load($code, 'code');
            if (!$storeView->getId()) {
                throw new ComponentException(
                    (string) __('Cannot find store with code %1', $code)
                );
            }
            $storeIds[] = $storeView->getId();
        }
        return implode(',', $storeIds);
    }

    /**
     * Build the `page_groups` structure Magento's Widget\Instance::beforeSave()
     * expects (a flat list where each item names a page group and nests its
     * params under that key) from the simplified configurator YAML list.
     *
     * Each YAML entry: { page_group, block, layout_handle?, for?, template?,
     * page_id?, entities? }. Layout placement (the original `@todo`) is now
     * configurable.
     *
     * @param array $pageGroups
     * @return array
     */
    public function buildPageGroups(array $pageGroups): array
    {
        $built = [];
        foreach ($pageGroups as $pageGroup) {
            $type = $pageGroup['page_group'] ?? 'all_pages';
            $built[] = [
                'page_group' => $type,
                $type => [
                    'page_id' => (string) ($pageGroup['page_id'] ?? '0'),
                    'layout_handle' => $pageGroup['layout_handle'] ?? 'default',
                    'for' => $pageGroup['for'] ?? 'all',
                    'block' => $pageGroup['block'] ?? '',
                    'template' => $pageGroup['template'] ?? '',
                    'entities' => $pageGroup['entities'] ?? '',
                ],
            ];
        }

        return $built;
    }

    /**
     * Reconcile a widget's page_groups against the source list. The built input
     * form is always carried onto the model (so a save triggered by another field
     * change does not drop the page_groups), but a change is only reported when the
     * stored placement actually differs from the source.
     *
     * The comparison is done in the simplified source shape because the stored
     * widget_instance_page rows use different column names (block_reference,
     * page_for, page_template) and a synthetic row id, so the previous raw
     * comparison against the built input form never matched and forced a re-save
     * on every run.
     *
     * @param Instance $widget the (hydrated) widget instance
     * @param array $sourceGroups the source `page_groups` list
     * @return bool true when the placement changed and a save is required
     */
    private function savePageGroups(Instance $widget, array $sourceGroups): bool
    {
        $current = $this->comparablePageGroups($this->getPageGroups($widget->getData('page_groups')));
        $desired = $this->comparablePageGroups($sourceGroups);

        $widget->setData('page_groups', $this->buildPageGroups($sourceGroups));

        if ($current === $desired) {
            $this->log->logComment('Widget page_groups unchanged', 1);
            return false;
        }

        $this->log->logInfo(sprintf('Widget page_groups = %s', print_r($sourceGroups, true)), 1);

        return true;
    }

    /**
     * Reduce a simplified page_groups list (as produced by getPageGroups(), or a
     * source list) to a canonical, order-insensitive set of comparable rows. The
     * synthetic page_id is intentionally excluded: stored rows carry the row's
     * primary key there, not a semantic page id (which, for specific pages, is
     * encoded in layout_handle and so is already compared).
     *
     * @param array $groups
     * @return string[] sorted JSON rows
     */
    private function comparablePageGroups(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $rows[] = (string) json_encode([
                'page_group' => (string) ($group['page_group'] ?? 'all_pages'),
                'block' => (string) ($group['block'] ?? ''),
                'layout_handle' => (string) ($group['layout_handle'] ?? 'default'),
                'for' => (string) ($group['for'] ?? 'all'),
                'template' => (string) ($group['template'] ?? ''),
                'entities' => (string) ($group['entities'] ?? ''),
            ]);
        }
        sort($rows);

        return $rows;
    }

    /**
     * Export current widget instances into the source format. Refresh mode
     * rewrites only the widgets already tracked in the source file (matched by
     * instance_type + title); full mode dumps every widget instance, optionally
     * filtered by an instance_type prefix. The forward transforms applied by
     * execute() are reversed: widget_parameters (serialized) -> `parameters`,
     * theme_id -> `theme` code, store_ids -> `stores` codes, and the native
     * page_groups rows -> the simplified list buildPageGroups() consumes.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild each tracked entry from its current DB state, preserving any
     * non-value keys (e.g. version). A tracked widget that no longer exists in
     * the DB is kept unchanged.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        $out = [];
        foreach ($existing as $entry) {
            if (!is_array($entry) || !isset($entry['instance_type'], $entry['title'])) {
                $out[] = $entry;
                continue;
            }

            $widget = $this->findWidgetByInstanceTypeAndTitle(
                (string) $entry['instance_type'],
                (string) $entry['title']
            );
            if ($widget === null) {
                $out[] = $entry;
                continue;
            }

            $out[] = $this->buildEntry($this->loadInstance((int) $widget->getId()), $entry);
        }

        return $out;
    }

    /**
     * Dump every widget instance, optionally filtered by an instance_type
     * prefix (or, failing that, an exact instance_type match).
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $out = [];
        foreach ($this->widgetCollection as $widget) {
            $instanceType = (string) $widget->getInstanceType();
            if ($filter !== null && $filter !== '' && !str_starts_with($instanceType, $filter)) {
                continue;
            }

            $out[] = $this->buildEntry($this->loadInstance((int) $widget->getId()));
        }

        return $out;
    }

    /**
     * Load a fully hydrated widget instance (the collection items don't carry
     * the page_groups rows, which are populated by the resource model on load).
     */
    private function loadInstance(int $instanceId): Instance
    {
        /** @var Instance $instance */
        $instance = $this->widgetFactory->create();
        $this->widgetResource->load($instance, $instanceId);

        return $instance;
    }

    /**
     * Build a single source-format entry from a loaded widget instance,
     * preserving any non-value keys (e.g. version) from the tracked entry.
     *
     * @param Instance $widget
     * @param array $existing
     * @return array
     */
    private function buildEntry(Instance $widget, array $existing = []): array
    {
        $entry = [
            'instance_type' => (string) $widget->getInstanceType(),
            'title' => (string) $widget->getTitle(),
        ];

        $themeCode = $this->getThemeCode((int) $widget->getThemeId());
        if ($themeCode !== null) {
            $entry['theme'] = $themeCode;
        }

        $stores = $this->getStoreCodes((string) $widget->getData('store_ids'));
        if ($stores !== []) {
            $entry['stores'] = $stores;
        }

        $parameters = $this->getParameters($widget->getData('widget_parameters'));
        if ($parameters !== []) {
            $entry['parameters'] = $parameters;
        }

        $pageGroups = $this->getPageGroups($widget->getData('page_groups'));
        if ($pageGroups !== []) {
            $entry['page_groups'] = $pageGroups;
        }

        if (isset($existing['version'])) {
            $entry['version'] = $existing['version'];
        }

        return $entry;
    }

    /**
     * Resolve theme_id back to its theme code (e.g. `Magento/blank`).
     */
    private function getThemeCode(int $themeId): ?string
    {
        if ($themeId <= 0) {
            return null;
        }

        $collection = $this->themeCollection->create();
        $theme = $collection->addFieldToFilter('theme_id', $themeId)->getFirstItem();

        return $theme->getId() ? (string) $theme->getData('code') : null;
    }

    /**
     * Resolve a comma-separated store_ids string back to store-view codes.
     *
     * @param string $storeIds
     * @return array
     */
    private function getStoreCodes(string $storeIds): array
    {
        $codes = [];
        foreach (array_filter(explode(',', $storeIds), 'strlen') as $storeId) {
            // Store id 0 is the "all store views" / admin scope; not a real code.
            if ((int) $storeId === 0) {
                continue;
            }
            $store = $this->storeFactory->create();
            $store->load((int) $storeId);
            if ($store->getId()) {
                $codes[] = (string) $store->getCode();
            }
        }

        return $codes;
    }

    /**
     * Reverse the widget_parameters transform: unserialize the stored value
     * into the `parameters` map.
     *
     * @param mixed $widgetParameters
     * @return array
     */
    private function getParameters(mixed $widgetParameters): array
    {
        if (is_array($widgetParameters)) {
            return $widgetParameters;
        }
        if (!is_string($widgetParameters) || $widgetParameters === '') {
            return [];
        }

        $parameters = $this->serializer->unserialize($widgetParameters);

        return is_array($parameters) ? $parameters : [];
    }

    /**
     * Reverse buildPageGroups(): turn the native page_groups rows
     * (widget_instance_page) back into the simplified configurator list.
     *
     * @param mixed $pageGroups
     * @return array
     */
    private function getPageGroups(mixed $pageGroups): array
    {
        if (!is_array($pageGroups)) {
            return [];
        }

        $built = [];
        foreach ($pageGroups as $pageGroup) {
            if (!is_array($pageGroup)) {
                continue;
            }
            $built[] = [
                'page_group' => (string) ($pageGroup['page_group'] ?? 'all_pages'),
                'block' => (string) ($pageGroup['block_reference'] ?? ''),
                'layout_handle' => (string) ($pageGroup['layout_handle'] ?? 'default'),
                'for' => (string) ($pageGroup['page_for'] ?? 'all'),
                'template' => (string) ($pageGroup['page_template'] ?? ''),
                'page_id' => (string) ($pageGroup['page_id'] ?? '0'),
                'entities' => (string) ($pageGroup['entities'] ?? ''),
            ];
        }

        return $built;
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
