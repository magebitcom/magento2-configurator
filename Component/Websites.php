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
use Magento\Store\Model\Group;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;
use Magento\Indexer\Model\IndexerFactory;
use Magento\Framework\Event\ManagerInterface;

class Websites implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'websites';
    private const DESCRIPTION = 'Component to manage Websites, Stores and Store Views';

    private bool $reindex = false;

    public function __construct(
        private readonly IndexerFactory $indexer,
        private readonly ManagerInterface $eventManager,
        private readonly WebsiteFactory $websiteFactory,
        private readonly StoreFactory $storeFactory,
        private readonly GroupFactory $groupFactory,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['websites']) || !is_array($data['websites'])) {
            $result->addError('No "websites" node found in the source data.');
            return $result;
        }

        $dryRun = $context->isDryRun();
        $mode = $context->getMode();

        try {
            // Loop through the websites
            foreach ($data['websites'] as $code => $websiteData) {
                // Process the website
                $website = $this->processWebsite($code, $websiteData, $mode, $dryRun, $result);

                // Loop through the store groups
                foreach ($websiteData['store_groups'] as $storeGroupData) {
                    // Process the store group
                    $storeGroup = $this->processStoreGroup($storeGroupData, $website, $mode, $dryRun, $result);

                    // Loop through the store views
                    foreach ($storeGroupData['store_views'] as $code => $storeViewData) {
                        // Process the store view
                        $this->processStoreView($code, $storeViewData, $storeGroup, $mode, $dryRun, $result);
                    }

                    // As the store may not be created yet, associated the default store to the store group
                    // has to be completed after all stores for the store group have been created.
                    $this->setDefaultStore($storeGroup, $storeGroupData, $mode, $dryRun, $result);
                }
            }

            if ($this->reindex === true) {
                if ($dryRun) {
                    $this->log->logInfo('[dry-run] Would run a reindex of the catalog_product_price table.');
                } else {
                    $this->log->logInfo('Running a reindex of the catalog_product_price table.');
                    $indexProcess = $this->indexer->create();
                    $indexProcess->load('catalog_product_price');
                    $indexProcess->reindexAll();
                }
            }
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * @param string $code
     * @param array $websiteData
     * @return Website
     * @SuppressWarnings(PHPMD)
     */
    protected function processWebsite($code, $websiteData, ComponentMode $mode, bool $dryRun, ComponentResult $result)
    {
        $logNest = 1;

        try {
            $this->log->logComment(sprintf("Does the website with code '%s' already exist?", $code), $logNest);

            $website = $this->websiteFactory->create();
            $website->load($code, 'code');

            // In create mode an existing website is returned untouched so child
            // groups/stores can still attach, but its attributes are not modified.
            $request = new ReconciliationRequest(self::ALIAS, 'website_' . $code, $mode, (bool) $website->getId());
            if ($website->getId() && $this->gate->decide($request)->isSkip()) {
                $this->log->logComment(sprintf("Website '%s' exists, skip modifying (create mode)", $code), $logNest);
                $result->recordSkipped();
                return $website;
            }

            $canSave = false;
            $isNew = false;

            // Check if it exists
            if ($website->getId()) {
                $this->log->logComment(sprintf("Website already exists with code '%s'", $code), $logNest);
            } else {
                $this->reindex = true;
                // If it does not exist, just set the existing data up with the website
                $canSave = true;
                $isNew = true;
                $this->log->logComment(sprintf("Creating a new Website with code '%s'", $code), $logNest);
                $website->setData($websiteData);
                $website->setCode($code);
            }

            // Loop through other website data attributes
            foreach ($website->getData() as $key => $value) {
                // Skip any array based values (likely to be passed from new website creation)
                if (is_array($value)) {
                    continue;
                }

                // Check if the data from the source has the data and that is different to that in magento
                if (isset($websiteData[$key]) && $websiteData[$key] != $value) {
                    // Set the new data
                    $this->log->logInfo(
                        sprintf("Change '%s' from '%s' to '%s'", $key, $value, $websiteData[$key]),
                        $logNest
                    );
                    $website->setData($key, $websiteData[$key]);
                    $canSave = true;
                } else {
                    // Skip setting the data
                    if ($website->getId()) {
                        $this->log->logComment(sprintf("No change for '%s' - '%s'", $key, $value), $logNest);
                    } else {
                        $this->log->logInfo(sprintf("New setting for '%s' - '%s'", $key, $value), $logNest);
                    }
                }
            }

            if ($canSave) {
                if ($dryRun) {
                    $this->log->logInfo(sprintf("[dry-run] Would save website '%s'", $code), $logNest);
                } else {
                    // Save the website
                    $website->getResource()->save($website);
                    $this->log->logInfo(sprintf("Saved website '%s'", $code), $logNest);
                }
                $isNew ? $result->recordCreated() : $result->recordUpdated();
            } else {
                $result->recordSkipped();
            }
            return $website;
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage(), $logNest);
            $result->addError($e->getMessage());
        }
    }

    /**
     * @param array $storeGroupData
     * @param Website $website
     * @return Group
     * @SuppressWarnings(PHPMD)
     */
    protected function processStoreGroup(
        $storeGroupData,
        Website $website,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ) {
        $logNest = 2;

        try {
            if (isset($storeGroupData['group_id'])) {
                $this->log->logComment(
                    sprintf("Does the store group with id '%s' already exist?", $storeGroupData['group_id']),
                    $logNest
                );
            } else {
                $this->log->logComment(
                    sprintf("Does the store group with name '%s' already exist?", $storeGroupData['name']),
                    $logNest
                );
            }

            // Attempt to load the Store Group via the object manager
            $storeGroup = $this->groupFactory->create();

            if (isset($storeGroupData['group_id'])) {
                $storeGroup->load($storeGroupData['group_id']);
            } else {
                $storeGroup->load($storeGroupData['name'], 'name');
            }

            // Create mode protects an existing store group from modification.
            $request = new ReconciliationRequest(
                self::ALIAS,
                'group_' . ($storeGroupData['group_id'] ?? $storeGroupData['name']),
                $mode,
                (bool) $storeGroup->getId()
            );
            if ($storeGroup->getId() && $this->gate->decide($request)->isSkip()) {
                $this->log->logComment(
                    sprintf("Store group '%s' exists, skip modifying (create mode)", $storeGroupData['name']),
                    $logNest
                );
                $result->recordSkipped();
                return $storeGroup;
            }

            $canSave = false;
            $isNew = false;

            // Check if the store group already exists
            if ($storeGroup->getId()) {
                $this->log->logComment(
                    sprintf("Store group already exists with name '%s'", $storeGroupData['name']),
                    $logNest
                );
            } else {
                // Create a new store group and set the basic data from source
                $this->log->logComment(
                    sprintf("Creating a new website with name '%s'", $storeGroupData['name']),
                    $logNest
                );
                $storeGroup->setData($storeGroupData);
                $storeGroup->setWebsite($website);
                $canSave = true;
                $isNew = true;
                $this->reindex = true;
            }

            foreach ($storeGroup->getData() as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                // Set data if the data from source exists and is not the same as what is on magento's
                if (isset($storeGroupData[$key]) && $storeGroupData[$key] != $value) {
                    $this->log->logInfo(
                        sprintf("Change '%s' from '%s' to '%s'", $key, $value, $storeGroupData[$key]),
                        $logNest
                    );
                    $storeGroup->setData($key, $storeGroupData[$key]);
                    $canSave = true;
                } else {
                    if ($storeGroup->getId()) {
                        $this->log->logComment(sprintf("No change for '%s' - '%s'", $key, $value), $logNest);
                    } else {
                        $this->log->logInfo(sprintf("New setting for '%s' - '%s'", $key, $value), $logNest);
                    }
                }
            }

            if ($canSave) {
                if ($dryRun) {
                    $this->log->logInfo(
                        sprintf("[dry-run] Would save store group '%s'", $storeGroup->getName()),
                        $logNest
                    );
                } else {
                    // Save the store group
                    $storeGroup->getResource()->save($storeGroup);
                    $this->log->logInfo(sprintf("Saved store group '%s'", $storeGroup->getName()), $logNest);
                }
                $isNew ? $result->recordCreated() : $result->recordUpdated();
            } else {
                $result->recordSkipped();
            }

            return $storeGroup;
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage(), $logNest);
            $result->addError($e->getMessage());
        }
    }

    /**
     * @param $code
     * @param $storeViewData
     * @param Group $storeGroup
     * @return Store
     * @SuppressWarnings(PHPMD)
     */
    protected function processStoreView(
        $code,
        $storeViewData,
        Group $storeGroup,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ) {
        $logNest = 3;

        try {
            $this->log->logComment(sprintf("Does the website with code '%s' already exist?", $code), $logNest);

            $storeView = $this->storeFactory->create();
            $storeView->load($code, 'code');

            // Create mode protects an existing store view from modification.
            $request = new ReconciliationRequest(self::ALIAS, 'storeview_' . $code, $mode, (bool) $storeView->getId());
            if ($storeView->getId() && $this->gate->decide($request)->isSkip()) {
                $this->log->logComment(sprintf("Store view '%s' exists, skip modifying (create mode)", $code), $logNest);
                $result->recordSkipped();
                return $storeView;
            }

            $canSave = false;
            $isNew = false;

            // Check if it exists
            if ($storeView->getId()) {
                $this->log->logComment(sprintf("Store view already exists with code '%s'", $code), $logNest);
            } else {
                // If it does not exist, just set the existing data up with the store view
                $canSave = true;
                $isNew = true;
                $this->reindex = true;
                $this->log->logComment(sprintf("Creating a new Website with code '%s'", $code), $logNest);
                $storeView->setData($storeViewData);
                $storeView->setCode($code);
            }

            // Check if the store group is the correct one
            if ($storeView->getStoreGroupId() != $storeGroup->getId()) {
                $this->log->logInfo(
                    sprintf(
                        "Setting new store group for store view '%s' from '%s' to '%s'",
                        $code,
                        $storeView->getStoreGroupId(),
                        $storeGroup->getId()
                    )
                );
                $storeView->setGroup($storeGroup);
                $canSave = true;
            }

            // Loop through other store view data attributes
            foreach ($storeView->getData() as $key => $value) {
                // Check if the data from the source has the data and that is different to that in magento
                if (isset($storeViewData[$key]) && $storeViewData[$key] != $value) {
                    // Set the new data
                    $this->log->logInfo(
                        sprintf("Change '%s' from '%s' to '%s'", $key, $value, $storeViewData[$key]),
                        $logNest
                    );
                    $storeView->setData($key, $storeViewData[$key]);
                    $canSave = true;
                } else {
                    // Skip setting the data
                    if ($storeView->getId()) {
                        $this->log->logComment(sprintf("No change for '%s' - '%s'", $key, $value), $logNest);
                    } else {
                        $this->log->logInfo(sprintf("New setting for '%s' - '%s'", $key, $value), $logNest);
                    }
                }
            }

            if ($canSave) {
                if ($dryRun) {
                    $this->log->logInfo(sprintf("[dry-run] Would save store view '%s'", $code), $logNest);
                } else {
                    // Save the store view
                    $storeView->getResource()->save($storeView);
                    $this->eventManager->dispatch('store_add', ['store' => $storeView]);
                    $this->log->logInfo(sprintf("Saved store view '%s'", $code), $logNest);
                }
                $isNew ? $result->recordCreated() : $result->recordUpdated();
            } else {
                $result->recordSkipped();
            }
            return $storeView;
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage(), $logNest);
            $result->addError($e->getMessage());
        }
    }

    /**
     * @param Group $storeGroup
     * @param $storeGroupData
     * @SuppressWarnings(PHPMD)
     */
    protected function setDefaultStore(
        Group $storeGroup,
        $storeGroupData,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $logNest = 2;

        try {
            $this->log->logComment(
                sprintf("Setting default store for the store group '%s", $storeGroup->getName()),
                $logNest
            );

            $storeView = $this->storeFactory->create();
            $storeView->load($storeGroupData['default_store'], 'code');

            if (!$storeView->getId()) {
                throw new ComponentException(
                    (string) __("Cannot find store view with code %1", $storeGroupData['default_store'])
                );
            }

            if ($storeView->getStoreGroupId() != $storeGroup->getId()) {
                throw new ComponentException(
                    (string) __(
                        "This store view code %1 does not belong to %2",
                        $storeGroupData['default_store'],
                        $storeGroup->getName()
                    )
                );
            }

            // Figure out if it needs changing
            if ($storeGroup->getDefaultStoreId() == $storeView->getId()) {
                $this->log->logComment(
                    sprintf("No change with the default store for '%s", $storeGroup->getName()),
                    $logNest
                );
            } elseif ($mode === ComponentMode::Create && $storeGroup->getDefaultStoreId()) {
                // Create mode does not repoint an existing group's default store.
                $this->log->logComment(
                    sprintf("Skip changing default store for existing group '%s' (create mode)", $storeGroup->getName()),
                    $logNest
                );
            } else {
                $storeGroup->setDefaultStoreId($storeView->getId());

                if ($dryRun) {
                    $this->log->logInfo(
                        sprintf(
                            "[dry-run] Would set default store view '%s' for store group '%s",
                            $storeView->getCode(),
                            $storeGroup->getName()
                        ),
                        $logNest
                    );
                } else {
                    $storeGroup->getResource()->save($storeGroup);
                    $this->log->logInfo(
                        sprintf(
                            "Set default store view '%s' for store group '%s",
                            $storeView->getCode(),
                            $storeGroup->getName()
                        ),
                        $logNest
                    );
                }
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage(), $logNest);
            $result->addError($e->getMessage());
        }
    }

    /**
     * Export the current store hierarchy into the source format. Refresh mode
     * rewrites only the websites/groups/store-views already tracked in the source
     * file (refreshing their scalar fields from the DB, preserving tracked entries
     * whose record no longer exists); full mode dumps every website in the DB
     * (optionally filtered by a website-code prefix).
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild every website in the DB in the source format, optionally limited to
     * websites whose code starts with the given filter.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $out = [];
        $collection = $this->websiteFactory->create()->getCollection();

        foreach ($collection as $website) {
            $code = (string) $website->getCode();
            if ($filter !== null && $filter !== '' && !str_starts_with($code, $filter)) {
                continue;
            }

            $out['websites'][$code] = $this->buildWebsite($website);
        }

        return ['websites' => $out['websites'] ?? []];
    }

    /**
     * Walk the tracked source structure and refresh each tracked website/group/
     * store-view from the DB. A tracked entity comes back with its FULL current
     * field set (every scalar column with a value) — not just the keys the source
     * file already listed — so admin changes to previously-untracked fields are
     * captured. Tracked entries whose record no longer exists are kept untouched,
     * and any non-DB structural keys carried in the source are preserved.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        if (!isset($existing['websites']) || !is_array($existing['websites'])) {
            return $existing;
        }

        $out = $existing;

        foreach ($existing['websites'] as $code => $websiteEntry) {
            $website = $this->websiteFactory->create();
            $website->load((string) $code, 'code');

            if (!$website->getId()) {
                // Record no longer exists in the DB; keep the tracked entry as-is.
                continue;
            }

            $out['websites'][$code] = $this->refreshWebsiteEntry((array) $websiteEntry, $website);
        }

        return $out;
    }

    /**
     * Refresh a tracked website entry, emitting every scalar field the live website
     * model carries (skipping empties), preserving the entry's non-DB structural
     * keys (e.g. version), and re-resolving the nested store groups from the DB.
     *
     * @param array $entry
     * @param Website $website
     * @return array
     */
    private function refreshWebsiteEntry(array $entry, Website $website): array
    {
        // Exclude internal/auto-increment ids and the redundant code (the YAML key)
        // so the export stays portable across environments.
        $refreshed = $this->scalarData($website->getData(), ['store_groups', 'website_id', 'default_group_id', 'code']);

        // Preserve non-DB structural keys the source tracked (e.g. version).
        foreach ($entry as $key => $value) {
            if ($key === 'store_groups' || array_key_exists($key, $refreshed)) {
                continue;
            }
            if (is_array($value) || $website->getData((string) $key) !== null) {
                continue;
            }
            $refreshed[$key] = $value;
        }

        // Re-resolve the nested store groups from the DB, refreshing each tracked
        // group in place so its key/order is kept and untracked DB fields surface.
        if (isset($entry['store_groups']) && is_array($entry['store_groups'])) {
            $groups = [];
            foreach ($entry['store_groups'] as $i => $groupEntry) {
                if (!is_array($groupEntry)) {
                    $groups[$i] = $groupEntry;
                    continue;
                }
                $group = $this->loadGroupForEntry($groupEntry, $website);
                if ($group === null || !$group->getId()) {
                    $groups[$i] = $groupEntry;
                    continue;
                }
                $groups[$i] = $this->refreshGroupEntry($groupEntry, $group);
            }
            $refreshed['store_groups'] = $groups;
        }

        return $refreshed;
    }

    /**
     * Refresh a tracked store-group entry, emitting every scalar field the live
     * group model carries (skipping empties), preserving the entry's non-DB
     * structural keys, re-resolving the `default_store` relation, and re-resolving
     * the nested store views from the DB.
     *
     * @param array $entry
     * @param Group $group
     * @return array
     */
    private function refreshGroupEntry(array $entry, Group $group): array
    {
        $refreshed = $this->scalarData($group->getData(), ['store_views', 'default_store', 'website_id']);

        // Preserve non-DB structural keys the source tracked (e.g. version).
        foreach ($entry as $key => $value) {
            if (in_array($key, ['store_views', 'default_store'], true) || array_key_exists($key, $refreshed)) {
                continue;
            }
            if (is_array($value) || $group->getData((string) $key) !== null) {
                continue;
            }
            $refreshed[$key] = $value;
        }

        // Re-resolve the default store relation from the DB.
        $defaultStore = $group->getDefaultStore();
        if ($defaultStore && $defaultStore->getId()) {
            $refreshed['default_store'] = (string) $defaultStore->getCode();
        } elseif (array_key_exists('default_store', $entry)) {
            $refreshed['default_store'] = $entry['default_store'];
        }

        // Re-resolve the nested store views from the DB.
        if (isset($entry['store_views']) && is_array($entry['store_views'])) {
            $views = [];
            foreach ($entry['store_views'] as $viewCode => $viewEntry) {
                if (!is_array($viewEntry)) {
                    $views[$viewCode] = $viewEntry;
                    continue;
                }
                $storeView = $this->storeFactory->create();
                $storeView->load((string) $viewCode, 'code');
                if (!$storeView->getId()) {
                    $views[$viewCode] = $viewEntry;
                    continue;
                }
                $views[$viewCode] = $this->refreshStoreViewEntry($viewEntry, $storeView);
            }
            $refreshed['store_views'] = $views;
        }

        return $refreshed;
    }

    /**
     * Refresh a tracked store-view entry, emitting every scalar field the live
     * store model carries (skipping empties), preserving the entry's non-DB
     * structural keys.
     *
     * @param array $entry
     * @param Store $storeView
     * @return array
     */
    private function refreshStoreViewEntry(array $entry, Store $storeView): array
    {
        $refreshed = $this->scalarData($storeView->getData(), ['store_id', 'website_id', 'group_id', 'code']);

        // Preserve non-DB structural keys the source tracked (e.g. version).
        foreach ($entry as $key => $value) {
            if (array_key_exists($key, $refreshed)) {
                continue;
            }
            if (is_array($value) || $storeView->getData((string) $key) !== null) {
                continue;
            }
            $refreshed[$key] = $value;
        }

        return $refreshed;
    }

    /**
     * Reduce a model's raw data to its scalar columns that carry a value, keeping
     * files clean: array values (sub-resources), null and empty-string values, and
     * any explicitly excluded keys (relations rebuilt separately) are dropped.
     *
     * @param array $data
     * @param string[] $exclude
     * @return array
     */
    private function scalarData(array $data, array $exclude = []): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $exclude, true) || is_array($value)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Build a full website entry (name + scalar columns + nested store groups) in
     * the source format from a live website model.
     *
     * @param Website $website
     * @return array
     */
    private function buildWebsite(Website $website): array
    {
        $entry = [
            'name' => (string) $website->getName(),
        ];

        $storeGroups = [];
        foreach ($website->getGroups() as $group) {
            $storeGroups[] = $this->buildGroup($group);
        }
        $entry['store_groups'] = $storeGroups;

        return $entry;
    }

    /**
     * Build a full store-group entry (name + root category + default store +
     * nested store views) in the source format from a live group model.
     *
     * @param Group $group
     * @return array
     */
    private function buildGroup(Group $group): array
    {
        $entry = [
            'group_id' => (int) $group->getId(),
            'name' => (string) $group->getName(),
            'root_category_id' => (int) $group->getRootCategoryId(),
        ];

        $defaultStore = $group->getDefaultStore();
        if ($defaultStore && $defaultStore->getId()) {
            $entry['default_store'] = (string) $defaultStore->getCode();
        }

        $storeViews = [];
        foreach ($group->getStores() as $store) {
            $storeViews[(string) $store->getCode()] = $this->buildStoreView($store);
        }
        $entry['store_views'] = $storeViews;

        return $entry;
    }

    /**
     * Build a full store-view entry (name + active flag) in the source format from
     * a live store model.
     *
     * @param Store $store
     * @return array
     */
    private function buildStoreView(Store $store): array
    {
        return [
            'name' => (string) $store->getName(),
            'is_active' => (int) $store->getIsActive(),
        ];
    }

    /**
     * Load the store group for a tracked group entry, mirroring execute(): by
     * `group_id` when present, otherwise by `name`. Restricted to the given website.
     *
     * @param array $entry
     * @param Website $website
     * @return Group|null
     */
    private function loadGroupForEntry(array $entry, Website $website): ?Group
    {
        if (isset($entry['group_id'])) {
            $group = $this->groupFactory->create();
            $group->load($entry['group_id']);
            return $group->getId() ? $group : null;
        }

        if (!isset($entry['name'])) {
            return null;
        }

        // Resolve by name within the website's groups to avoid cross-website clashes.
        foreach ($website->getGroups() as $group) {
            if ((string) $group->getName() === (string) $entry['name']) {
                return $group;
            }
        }

        return null;
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
