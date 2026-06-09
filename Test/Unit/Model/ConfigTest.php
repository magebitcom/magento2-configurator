<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model;

use Magebit\Configurator\Api\Data\ConfigInterface;
use Magebit\Configurator\Model\Config;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        // AbstractModel's constructor touches the framework ObjectManager, which
        // isn't booted in a unit test. The unit ObjectManager helper builds the
        // model (and runs _construct) without that framework wiring.
        $objectManager = new ObjectManager($this);

        $this->config = $objectManager->getObject(Config::class);
    }

    public function testIsAConfigInterface(): void
    {
        $this->assertInstanceOf(ConfigInterface::class, $this->config);
    }

    public function testSetNameStoresValueUnderTheNameKey(): void
    {
        $this->config->setName('web/secure/base_url');

        $this->assertSame('web/secure/base_url', $this->config->getData(ConfigInterface::NAME));
    }

    public function testGetNameReturnsThePreviouslySetName(): void
    {
        $this->config->setName('general/store_information/name');

        $this->assertSame('general/store_information/name', $this->config->getName());
    }

    public function testSetNameIsFluentAndReturnsSelf(): void
    {
        $this->assertSame($this->config, $this->config->setName('foo'));
    }

    public function testSetValueStoresValueUnderTheValueKey(): void
    {
        $this->config->setValue('https://example.com/');

        $this->assertSame('https://example.com/', $this->config->getData(ConfigInterface::VALUE));
    }

    public function testGetValueReturnsThePreviouslySetValue(): void
    {
        $this->config->setValue('Acme Store');

        $this->assertSame('Acme Store', $this->config->getValue());
    }

    public function testSetValueIsFluentAndReturnsSelf(): void
    {
        $this->assertSame($this->config, $this->config->setValue('bar'));
    }

    public function testNameAndValueAreStoredIndependently(): void
    {
        $this->config->setName('design/theme/theme_id');
        $this->config->setValue('4');

        $this->assertSame('design/theme/theme_id', $this->config->getName());
        $this->assertSame('4', $this->config->getValue());
    }

    public function testValueRoundTripsEmptyAndUnicodeStrings(): void
    {
        $this->config->setValue('');
        $this->assertSame('', $this->config->getValue());

        $this->config->setValue('héllo · 世界');
        $this->assertSame('héllo · 世界', $this->config->getValue());
    }

    public function testResourceModelIsRegistered(): void
    {
        // _construct() runs during instantiation and binds the resource model name
        // via _init(). getResourceName() can't be used here because the unit
        // ObjectManager helper pre-injects a generated resource-model mock, so the
        // method returns that mock's class. The registered name is the meaningful
        // contract, so we read the protected _resourceName the model recorded.
        $reflection = new \ReflectionProperty(\Magento\Framework\Model\AbstractModel::class, '_resourceName');
        $reflection->setAccessible(true);

        $this->assertSame(
            \Magebit\Configurator\Model\ResourceModel\ConfigResource::class,
            $reflection->getValue($this->config)
        );
    }
}
