<?php

declare(strict_types=1);

namespace CtiDigital\Configurator\Model;

use CtiDigital\Configurator\Api\Data\ConfigInterface;
use Magento\Framework\Model\AbstractModel;
use CtiDigital\Configurator\Model\ResourceModel\ConfigResource;

class Config extends AbstractModel implements ConfigInterface
{
    protected $_eventPrefix = 'ctidigital_configurator_config_model';

    protected function _construct()
    {
        $this->_init(ConfigResource::class);
    }

    public function getName(): string
    {
        return $this->getData(self::NAME);
    }

    public function setName(string $name): ConfigInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getValue(): string
    {
        return $this->getData(self::VALUE);
    }

    public function setValue(string $value): ConfigInterface
    {
        return $this->setData(self::VALUE, $value);
    }
}
