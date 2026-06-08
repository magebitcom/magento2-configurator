<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Component\CatalogPriceRules\CatalogPriceRulesProcessor;
use Magento\CatalogRule\Api\Data\RuleInterfaceFactory;

class CatalogPriceRules implements ComponentInterface
{
    /**
     * @var string
     */
    protected $alias = 'catalog_price_rules';

    /**
     * @var string
     */
    protected $name = 'Catalog Price Rules';

    /**
     * @var string
     */
    protected $description = 'Component to manage Catalog Price Rules';

    /**
     * @var CatalogPriceRulesProcessor
     */
    private $processor;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * CatalogPriceRules constructor.
     *
     * @param LoggerInterface $log
     * @param CatalogPriceRulesProcessor $processor
     */
    public function __construct(
        CatalogPriceRulesProcessor $processor,
        LoggerInterface $log
    ) {
        $this->processor = $processor;
        $this->log = $log;
    }

    /**
     * This method should be used to process the data and populate the Magento Database.
     *
     * @param $data
     *
     * @return void
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        $rules = $data['rules'] ?: [];
        $config = $data['config'] ?: [];

        $this->processor->setData($rules)
            ->setConfig($config)
            ->process();

        return $result;
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
