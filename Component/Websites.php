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
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
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

class Websites implements ComponentInterface
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
