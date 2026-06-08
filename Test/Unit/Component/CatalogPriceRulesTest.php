<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Api\ComponentProcessorInterface;
use Magebit\Configurator\Component\CatalogPriceRules;
use Magebit\Configurator\Component\CatalogPriceRules\CatalogPriceRulesProcessor;
use Magebit\Configurator\Api\LoggerInterface;

/**
 * Class CatalogPriceRulesTest
 * @codingStandardsIgnoreStart
 * @SuppressWarnings(PHPMD)
 */
class CatalogPriceRulesTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CatalogPriceRules
     */
    private $catalogPriceRules;

    /**
     * @var LoggerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $log;

    /**
     * @var ComponentProcessorInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $mockComponentProcessor;

    protected function setUp(): void
    {
        $this->mockComponentProcessor = $this->getMockBuilder(CatalogPriceRulesProcessor::class)
            ->disableOriginalConstructor()
            ->setMethods(['setData', 'setConfig', 'process'])
            ->getMock();

        $this->log = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->catalogPriceRules = new CatalogPriceRules(
            $this->mockComponentProcessor,
            $this->log
        );
    }

    public function testProcessDataExecution()
    {
        $this->mockComponentProcessor->expects($this->once())
            ->method('setData')
            ->willReturn($this->mockComponentProcessor);

        $this->mockComponentProcessor->expects($this->once())
            ->method('setConfig')
            ->willReturn($this->mockComponentProcessor);

        $this->mockComponentProcessor->expects($this->once())
            ->method('process');

        $this->catalogPriceRules->execute();
    }
}
