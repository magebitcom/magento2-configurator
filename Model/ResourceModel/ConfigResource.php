<?php

declare(strict_types=1);

namespace CtiDigital\Configurator\Model\ResourceModel;

use CtiDigital\Configurator\Api\Data\ConfigInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ConfigResource extends AbstractDb
{
    /**
     * @var string
     */
    protected string $_eventPrefix = 'ctidigital_configurator_config_resource_model';

    /**
     * Initialize resource model.
     */
    protected function _construct()
    {
        $this->_init('configurator_config', ConfigInterface::ID);
        $this->_useIsObjectNew = true;
    }
}
