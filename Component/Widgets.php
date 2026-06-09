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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
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

class Widgets implements ComponentInterface
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
        private readonly WidgetInstanceResource $widgetResource
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
                $this->processWidget($widgetData, $context->isDryRun(), $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    public function processWidget(array $widgetData, bool $dryRun, ComponentResult $result): void
    {
        try {
            // Capture the configured stores so block references resolve in the right scope.
            $stores = (isset($widgetData['stores']) && is_array($widgetData['stores']))
                ? $widgetData['stores']
                : null;

            $widget = $this->findWidgetByInstanceTypeAndTitle($widgetData['instance_type'], $widgetData['title']);

            $isNew = false;
            $canSave = false;
            if ($widget === null) {
                $isNew = true;
                $canSave = true;
                /**
                 * @var Instance $widget
                 */
                $widget = $this->widgetFactory->create();
            }

            foreach ($widgetData as $key => $value) {
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
                $result->recordSkipped();
                return;
            }

            if ($dryRun) {
                $this->log->logInfo(
                    sprintf('[dry-run] Would save Widget %s', $widget->getTitle()),
                    1
                );
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
            $isNew ? $result->recordCreated() : $result->recordUpdated();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
