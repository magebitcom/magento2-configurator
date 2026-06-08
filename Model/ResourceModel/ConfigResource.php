<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model\ResourceModel;

use Magebit\Configurator\Api\Data\ConfigInterface;
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
