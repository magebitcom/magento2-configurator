<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Test\Integration\Component;

use Magebit\Configurator\Console\Command\RunCommand;
use Magento\CatalogRule\Model\ResourceModel\Rule\Collection;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Class CatalogPriceRulesTest
 * @codingStandardsIgnoreStart
 * @SuppressWarnings(PHPMD)
 */
class CatalogPriceRulesTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var RuleFactory
     */
    private $ruleFactory;

    /**
     * @var Collection
     */
    private $collection;

    /**
     * @var CommandTester
     */
    private $cmdTester;

    /**
     * @var RunCommand
     */
    private $command;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    public function setUp()
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->collection = $this->objectManager->create(Collection::class);
        $this->ruleFactory = $this->objectManager->create(RuleFactory::class);
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testRequestedRulesAreCreated()
    {
        $this->executeCommand();

        $expectedRulesCount = 2;

        $this->assertEquals($expectedRulesCount, $this->collection->count());
    }

    private function executeCommand()
    {
        $this->command = $this->objectManager->create(RunCommand::class);
        $this->command->addOption('verbose', '-v');
        $this->cmdTester = new CommandTester($this->command);
        $this->cmdTester->execute(['--component' => ['catalog_price_rules'], '--env' => 'local']);
    }
}
