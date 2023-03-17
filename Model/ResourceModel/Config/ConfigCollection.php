<?php

declare(strict_types=1);

namespace CtiDigital\Configurator\Model\ResourceModel\Config;

use CtiDigital\Configurator\Model\Config;
use CtiDigital\Configurator\Model\ResourceModel\ConfigResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class ConfigCollection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'ctidigital_configurator_config_collection';

    /**
     * Initialize collection model.
     */
    protected function _construct()
    {
        $this->_init(Config::class, ConfigResource::class);
    }
}
