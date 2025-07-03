<?php

namespace CtiDigital\Configurator\Component;

use CtiDigital\Configurator\Api\ComponentInterface;
use CtiDigital\Configurator\Api\LoggerInterface;
use CtiDigital\Configurator\Exception\ComponentException;
use Magento\Widget\Model\ResourceModel\Widget\Instance\Collection as WidgetCollection;
use Magento\Widget\Model\Widget\Instance;
use Magento\Widget\Model\Widget\InstanceFactory as WidgetInstanceFactory;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory;
use Magento\Store\Model\StoreFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\App\Area as AppArea;
use Magento\Framework\App\State as AppState;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class Widgets implements ComponentInterface
{

    protected $alias = 'widgets';
    protected $name = 'Widgets';
    protected $description = 'Component to manage CMS Widgets';

    /**
     * @var WidgetCollection
     */
    private $widgetCollection;

    /**
     * @var WidgetInstanceFactory
     */
    private $widgetFactory;

    /**
     * @var ThemeCollection
     */
    private $themeCollection;

    /**
     * @var StoreFactory
     */
    private $storeFactory;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @var AppState
     */
    private $appState;

    /**
     * @var BlockRepositoryInterface
     */
    private $blockRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $criteriaBuilder;

    /**
     * Widgets constructor.
     * @param WidgetCollection $collection
     * @param WidgetInstanceFactory $widgetFactory
     * @param StoreFactory $storeFactory
     * @param ThemeCollectionFactory $themeCollection
     * @param SerializerInterface $serializer
     * @param LoggerInterface $log
     * @param AppState $appState
     * @param BlockRepositoryInterface $blockRepository
     * @param SearchCriteriaBuilder $criteriaBuilder
     */
    public function __construct(
        WidgetCollection $collection,
        WidgetInstanceFactory $widgetFactory,
        StoreFactory $storeFactory,
        ThemeCollectionFactory $themeCollection,
        SerializerInterface $serializer,
        LoggerInterface $log,
        AppState $appState,
        BlockRepositoryInterface $blockRepository,
        SearchCriteriaBuilder $criteriaBuilder
    ) {
        $this->widgetCollection = $collection;
        $this->widgetFactory = $widgetFactory;
        $this->themeCollection = $themeCollection;
        $this->storeFactory = $storeFactory;
        $this->serializer = $serializer;
        $this->log = $log;
        $this->appState = $appState;
        $this->blockRepository = $blockRepository;
        $this->criteriaBuilder = $criteriaBuilder;
    }

    public function execute($data = null)
    {
        try {
            foreach ($data as $widgetData) {
                $this->processWidget($widgetData);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    public function processWidget($widgetData)
    {
        try {
            $widget = $this->findWidgetByInstanceTypeAndTitle($widgetData['instance_type'], $widgetData['title']);

            $canSave = false;
            if ($widget === null) {
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
                    $value = $this->populateWidgetParameters($value);
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

            if ($canSave) {
                $this->appState->emulateAreaCode(
                    AppArea::AREA_FRONTEND,
                    function () use ($widget) {
                        $widget->save();
                    }
                );

                $this->log->logInfo(sprintf("Saved Widget %s", $widget->getTitle()), 1);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * @param $widgetInstanceType
     * @param $widgetTitle
     * @return \Magento\Framework\DataObject|null
     * @throws ComponentException
     * @todo get this one to work instead of findWidgetByInstanceTypeAndTitle()
     */
    public function getWidgetByInstanceTypeAndTitle($widgetInstanceType, $widgetTitle)
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
            throw new ComponentException('Application Error: Need to figure out how to handle same titled widgets');
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
     * @param $widgetInstanceType
     * @param $widgetTitle
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

    public function getThemeId($themeCode)
    {

        // Filter Theme Collection
        $collection = $this->themeCollection->create();
        $themes = $collection->addFilter('code', $themeCode);

        if ($themes->count() == 0) {
            throw new ComponentException(sprintf('Could not find any themes with the theme code %s', $themeCode));
        }

        $theme = $themes->getFirstItem();

        return $theme->getId();
    }

    /**
     * @param array $parameters
     * @return string
     * @todo better support with parameters that reference IDs of objects
     */
    public function populateWidgetParameters(array $parameters)
    {
        // Process block_identifier if present
        $processedParameters = $this->processBlockIdentifiers($parameters);

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
     * @return array
     */
    private function processBlockIdentifiers(array $parameters)
    {
        $processedParameters = $parameters;

        foreach ($parameters as $key => $value) {
            if ($key === 'block_identifier' && is_string($value)) {
                try {
                    $blockId = $this->getBlockIdByIdentifier($value);
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
     * @return string
     * @throws ComponentException
     */
    private function getBlockIdByIdentifier($identifier)
    {
        try {
            $searchCriteria = $this->criteriaBuilder
                ->addFilter('identifier', $identifier)
                ->create();

            $blocks = $this->blockRepository->getList($searchCriteria);

            if ($blocks->getTotalCount() === 0) {
                throw new ComponentException(sprintf('CMS Block with identifier "%s" not found', $identifier));
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

            throw new ComponentException(sprintf('No block found with identifier "%s"', $identifier));
        } catch (\Exception $e) {
            throw new ComponentException(
                sprintf('Error retrieving CMS block with identifier "%s": %s', $identifier, $e->getMessage())
            );
        }
    }

    /**
     * @param $stores
     * @return string
     */
    public function getCommaSeparatedStoreIds($stores)
    {
        $storeIds = [];
        foreach ($stores as $code) {
            $storeView = $this->storeFactory->create();
            $storeView->load($code, 'code');
            if (!$storeView->getId()) {
                throw new ComponentException(sprintf('Cannot find store with code %s', $code));
            }
            $storeIds[] = $storeView->getId();
        }
        return implode(',', $storeIds);
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }
}
