<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use ArrayIterator;
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Component\CatalogPriceRules;
use Magebit\Configurator\Component\CatalogPriceRules\CatalogPriceRulesProcessor;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magento\CatalogRule\Model\ResourceModel\Rule\Collection;
use Magento\CatalogRule\Model\Rule;
use Magento\CatalogRule\Model\RuleFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// Magento\CatalogRule\Model\RuleFactory is a DI-generated factory; nothing in the
// unit-test environment guarantees it has been generated, so PHPUnit's
// reflection-based mock builder may not find it. Provide a minimal stub matching
// the generated factory signature (create() yields a Rule model) so the component
// can be constructed and the factory mocked.
if (!class_exists(RuleFactory::class)) {
    eval(
        'namespace Magento\CatalogRule\Model;'
        . ' class RuleFactory {'
        . ' public function create(array $data = []) {} }'
    );
}

class CatalogPriceRulesTest extends TestCase
{
    private CatalogPriceRulesProcessor&MockObject $processor;
    private LoggerInterface&MockObject $log;
    private RuleFactory&MockObject $ruleFactory;
    private CatalogPriceRules $component;

    protected function setUp(): void
    {
        $this->processor = $this->createMock(CatalogPriceRulesProcessor::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->ruleFactory = $this->getMockBuilder(RuleFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->component = new CatalogPriceRules(
            $this->processor,
            $this->log,
            $this->ruleFactory
        );
    }

    public function testProcessesRulesInCreateMode(): void
    {
        // The component delegates the create/maintain decision to the processor;
        // it must hand the rules, config and mode through and record the count.
        $rules = [
            'rule1' => ['name' => 'Summer Sale'],
            'rule2' => ['name' => 'Winter Sale'],
        ];

        $this->processor->expects($this->once())->method('setData')->with($rules)->willReturnSelf();
        $this->processor->expects($this->once())->method('setConfig')->with(['apply_all' => true])->willReturnSelf();
        $this->processor->expects($this->once())->method('setMode')->with(ComponentMode::Create)->willReturnSelf();
        $this->processor->expects($this->once())->method('process');

        $result = $this->execute(['rules' => $rules, 'config' => ['apply_all' => true]]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getCreated());
    }

    public function testProcessesRulesInMaintainMode(): void
    {
        $rules = ['rule1' => ['name' => 'Summer Sale']];

        $this->processor->method('setData')->willReturnSelf();
        $this->processor->method('setConfig')->with([])->willReturnSelf();
        $this->processor->expects($this->once())->method('setMode')->with(ComponentMode::Maintain)->willReturnSelf();
        $this->processor->expects($this->once())->method('process');

        $result = $this->execute(['rules' => $rules], false, ComponentMode::Maintain);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testDryRunDoesNotInvokeProcessor(): void
    {
        $rules = [
            'rule1' => ['name' => 'Summer Sale'],
            'rule2' => ['name' => 'Winter Sale'],
        ];

        // Dry-run records intent but must never touch the processor.
        $this->processor->expects($this->never())->method('setData');
        $this->processor->expects($this->never())->method('process');

        $result = $this->execute(['rules' => $rules], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getCreated());
    }

    public function testRecordsErrorWhenRulesNodeMissing(): void
    {
        $this->processor->expects($this->never())->method('process');

        $result = $this->execute(['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenRulesNodeIsNotArray(): void
    {
        $this->processor->expects($this->never())->method('process');

        $result = $this->execute(['rules' => 'not-an-array']);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testFullExportDumpsEveryRuleWithMappedFields(): void
    {
        $rule = $this->givenRuleModel([
            'name' => 'Summer Sale',
            'description' => 'Hot deals',
            'is_active' => 1,
            'sort_order' => 5,
            'website_ids' => [1, 2],
            'customer_group_ids' => [0, 1],
            'from_date' => '2026-06-01',
            'to_date' => '',
            'conditions_serialized' => '{}',
            'actions_serialized' => '{}',
            'simple_action' => 'by_percent',
            'discount_amount' => '10.0000',
            'stop_rules_processing' => 0,
        ]);

        $collection = $this->givenCollection([$rule]);
        // Full export has no filter, so addFieldToFilter must not be applied.
        $collection->expects($this->never())->method('addFieldToFilter');
        $this->ruleFactory->method('create')->willReturn($this->givenRuleWithCollection($collection));

        $out = $this->component->export(new ExportContext([], true, null, false));

        $this->assertArrayHasKey('rules', $out);
        $this->assertArrayHasKey('rule1', $out['rules']);

        $entry = $out['rules']['rule1'];
        $this->assertSame('Summer Sale', $entry['name']);
        $this->assertSame('Hot deals', $entry['description']);
        $this->assertSame(1, $entry['is_active']);
        $this->assertSame(5, $entry['sort_order']);
        $this->assertSame([1, 2], $entry['website_ids']);
        $this->assertSame([0, 1], $entry['customer_group_ids']);
        // 2026-06-01 round-trips into the source d/m/Y form.
        $this->assertSame('1/6/2026', $entry['from_date']);
        // Empty stored date round-trips as null.
        $this->assertNull($entry['to_date']);
    }

    public function testFullExportAppliesNamePrefixFilter(): void
    {
        $rule = $this->givenRuleModel(['name' => 'Summer Sale']);
        $collection = $this->givenCollection([$rule]);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('name', ['like' => 'Summer%'])
            ->willReturnSelf();
        $this->ruleFactory->method('create')->willReturn($this->givenRuleWithCollection($collection));

        $out = $this->component->export(new ExportContext([], true, 'Summer', false));

        $this->assertSame('Summer Sale', $out['rules']['rule1']['name']);
    }

    public function testRefreshRebuildsTrackedRuleAndPreservesStructuralKeys(): void
    {
        $existing = [
            'rules' => [
                'rule1' => ['name' => 'Summer Sale', 'version' => 3, 'is_active' => 0],
            ],
        ];

        $rule = $this->givenRuleModel([
            'name' => 'Summer Sale',
            'is_active' => 1,
            'simple_action' => 'by_percent',
            'discount_amount' => '15.0000',
        ]);
        $rule->method('getId')->willReturn(7);

        // findRuleByName(): collection filtered by exact name, size 1, getFirstItem().
        $collection = $this->givenCollection([]);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('name', 'Summer Sale')
            ->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($rule);
        $this->ruleFactory->method('create')->willReturn($this->givenRuleWithCollection($collection));

        $out = $this->component->export(new ExportContext($existing, false, null, false));

        $entry = $out['rules']['rule1'];
        // Re-read from the DB overrides the stale tracked value.
        $this->assertSame(1, $entry['is_active']);
        $this->assertSame('by_percent', $entry['simple_action']);
        // Structural key not part of the export field set is preserved.
        $this->assertSame(3, $entry['version']);
    }

    public function testRefreshKeepsTrackedRuleWhenNoLongerInDb(): void
    {
        $existing = [
            'rules' => [
                'rule1' => ['name' => 'Gone', 'version' => 2],
            ],
        ];

        // Collection finds no single match (size 0) -> findRuleByName returns null.
        $collection = $this->givenCollection([]);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(0);
        $this->ruleFactory->method('create')->willReturn($this->givenRuleWithCollection($collection));

        $out = $this->component->export(new ExportContext($existing, false, null, false));

        // Entry kept untouched.
        $this->assertSame(['name' => 'Gone', 'version' => 2], $out['rules']['rule1']);
    }

    /**
     * @param array $data full source override; the component reads the `rules` node from it
     */
    private function execute(
        array $data,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function givenRuleModel(array $data): Rule&MockObject
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getName', 'getId', 'getWebsiteIds', 'getCustomerGroupIds'])
            ->getMock();

        $rule->method('getData')->willReturnCallback(
            static fn (string $key): mixed => $data[$key] ?? null
        );
        $rule->method('getName')->willReturn((string) ($data['name'] ?? ''));
        $rule->method('getWebsiteIds')->willReturn($data['website_ids'] ?? []);
        $rule->method('getCustomerGroupIds')->willReturn($data['customer_group_ids'] ?? []);

        return $rule;
    }

    /**
     * @param Rule[] $rules
     */
    private function givenCollection(array $rules): Collection&MockObject
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem', 'getIterator'])
            ->getMock();

        $collection->method('getIterator')->willReturn(new ArrayIterator($rules));

        return $collection;
    }

    /**
     * Wraps a collection in a Rule model whose getCollection() returns it,
     * mirroring $this->ruleFactory->create()->getCollection().
     */
    private function givenRuleWithCollection(Collection&MockObject $collection): Rule&MockObject
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollection'])
            ->getMock();
        $rule->method('getCollection')->willReturn($collection);

        return $rule;
    }
}
