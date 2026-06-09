<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Component\TaxRules;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\Calculation\Rule;
use Magento\Tax\Model\Calculation\RuleFactory;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\ClassModelFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rule as TaxRuleResource;
use Magento\Tax\Model\ResourceModel\TaxClass as TaxClassResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaxRulesTest extends TestCase
{
    private RateFactory&MockObject $rateFactory;
    private ClassModelFactory&MockObject $classModelFactory;
    private RuleFactory&MockObject $ruleFactory;
    private TaxRuleResource&MockObject $taxRuleResource;
    private TaxClassResource&MockObject $taxClassResource;
    private LoggerInterface&MockObject $log;
    private TaxRules $component;

    protected function setUp(): void
    {
        $this->rateFactory = $this->getMockBuilder(RateFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->classModelFactory = $this->getMockBuilder(ClassModelFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->ruleFactory = $this->getMockBuilder(RuleFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->taxRuleResource = $this->createMock(TaxRuleResource::class);
        $this->taxClassResource = $this->createMock(TaxClassResource::class);
        $this->log = $this->createMock(LoggerInterface::class);

        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new TaxRules(
            $this->rateFactory,
            $this->classModelFactory,
            $this->ruleFactory,
            $this->taxRuleResource,
            $this->taxClassResource,
            $this->log,
            $gate
        );
    }

    public function testCreatesTaxRuleWhenItDoesNotExist(): void
    {
        $this->givenRateLookupReturnsId(5);
        $this->givenTaxClassLookupReturnsId(3);

        // No existing rule with this code -> gate decides Create.
        $rule = $this->givenRuleLookup(0);
        $rule->method('setCode')->willReturnSelf();
        $rule->method('setTaxRateIds')->willReturnSelf();
        $rule->method('setCustomerTaxClassIds')->willReturnSelf();
        $rule->method('setProductTaxClassIds')->willReturnSelf();
        $rule->method('setPriority')->willReturnSelf();
        $rule->method('setCalculateSubtotal')->willReturnSelf();
        $rule->method('setPosition')->willReturnSelf();

        $this->taxRuleResource->expects($this->once())->method('save')->with($rule);

        $result = $this->execute($this->sourceRows());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingRule(): void
    {
        $this->givenRateLookupReturnsId(5);
        $this->givenTaxClassLookupReturnsId(3);

        // Existing rule (id 42) in create mode without a version bump -> skipped.
        $this->givenRuleLookup(42);

        $this->taxRuleResource->expects($this->never())->method('save');

        $result = $this->execute($this->sourceRows());

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingRule(): void
    {
        $this->givenRateLookupReturnsId(5);
        $this->givenTaxClassLookupReturnsId(3);

        $existing = $this->givenRuleLookup(42);
        $existing->method('setCode')->willReturnSelf();
        $existing->method('setTaxRateIds')->willReturnSelf();
        $existing->method('setCustomerTaxClassIds')->willReturnSelf();
        $existing->method('setProductTaxClassIds')->willReturnSelf();
        $existing->method('setPriority')->willReturnSelf();
        $existing->method('setCalculateSubtotal')->willReturnSelf();
        $existing->method('setPosition')->willReturnSelf();

        // Maintain mode updates the existing differing rule.
        $this->taxRuleResource->expects($this->once())->method('save')->with($existing);

        $result = $this->execute($this->sourceRows(), false, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenRateLookupReturnsId(5);
        $this->givenTaxClassLookupReturnsId(3);
        $this->givenRuleLookup(0);

        // Dry run records the intent but never saves.
        $this->taxRuleResource->expects($this->never())->method('save');

        $result = $this->execute($this->sourceRows(), true);

        $this->assertSame(1, $result->getCreated());
    }

    public function testDryRunDoesNotCreateMissingTaxClass(): void
    {
        $this->givenRateLookupReturnsId(5);
        // Tax class does not exist (id 0); under dry run it must not be saved.
        $this->givenTaxClassLookupReturnsId(0);
        $this->givenRuleLookup(0);

        $this->taxClassResource->expects($this->never())->method('save');
        $this->taxRuleResource->expects($this->never())->method('save');

        $result = $this->execute($this->sourceRows(), true);

        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenNoRowData(): void
    {
        // No header row present -> nothing to import.
        $this->taxRuleResource->expects($this->never())->method('save');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testSkipsRowWithEmptyCode(): void
    {
        // Header present, but the only data row has an empty code -> skipped, no save.
        $this->taxRuleResource->expects($this->never())->method('save');

        $rows = [
            0 => $this->header(),
            1 => ['', '', '', '', '', '', ''],
        ];

        $result = $this->execute($rows);

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemovesExistingRule(): void
    {
        // Existing rule (id 42) flagged with remove -> deleted once, never saved.
        $existing = $this->givenRuleLookup(42);

        $this->taxRuleResource->expects($this->never())->method('save');
        $this->taxRuleResource->expects($this->once())->method('delete')->with($existing);

        $result = $this->execute($this->removalRows());

        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testRemoveAbsentRuleIsSkipped(): void
    {
        // No rule with this code (id 0) -> nothing deleted, recorded as a skip.
        $this->givenRuleLookup(0);

        $this->taxRuleResource->expects($this->never())->method('save');
        $this->taxRuleResource->expects($this->never())->method('delete');

        $result = $this->execute($this->removalRows());

        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunRemovalDoesNotDelete(): void
    {
        // Existing rule under dry run: intent recorded, nothing deleted.
        $this->givenRuleLookup(42);

        $this->taxRuleResource->expects($this->never())->method('save');
        $this->taxRuleResource->expects($this->never())->method('delete');

        $result = $this->execute($this->removalRows(), true);

        $this->assertSame(1, $result->getRemoved());
    }

    public function testExportFullDumpsEveryRule(): void
    {
        // A single rule mock plays every role: the iterated item, the
        // collection-getFirstItem id source, and the loaded full rule.
        $rule = $this->fullyLoadedRule(7, 'VAT20');

        $collection = $this->createMock(\Magento\Tax\Model\ResourceModel\Calculation\Rule\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($rule);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$rule]));

        $rule->method('getCollection')->willReturn($collection);
        $this->ruleFactory->method('create')->willReturn($rule);
        $this->taxRuleResource->method('load')->willReturnSelf();

        // Rate ids / class ids resolve back to readable codes/names.
        $this->givenRateLoadById(5, 'standard-rate');
        $this->givenClassLoadById(3, 'Retail Customer');

        $rows = $this->component->export(new ExportContext([], true));

        // Header first, then one rebuilt data row for the single rule.
        $this->assertSame($this->header(), $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('VAT20', $rows[1][0]);
        $this->assertSame('standard-rate', $rows[1][1]);
        $this->assertSame('Retail Customer', $rows[1][2]);
    }

    public function testExportRefreshKeepsUntrackedRowAndUntouchedWhenRuleMissing(): void
    {
        $existing = [
            0 => $this->header(),
            1 => ['VAT20', 'standard-rate', 'Retail Customer', 'Taxable Goods', '0', '0', '1'],
        ];

        // The tracked rule no longer exists in the DB (id 0) -> its existing row is kept verbatim.
        $collection = $this->createMock(\Magento\Tax\Model\ResourceModel\Calculation\Rule\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($this->ruleWithId(0));

        $this->ruleFactory->method('create')->willReturnCallback(function () use ($collection) {
            $rule = $this->createMock(Rule::class);
            $rule->method('getCollection')->willReturn($collection);
            return $rule;
        });

        $rows = $this->component->export(new ExportContext($existing, false));

        $this->assertSame($this->header(), $rows[0]);
        $this->assertSame($existing[1], $rows[1]);
    }

    public function testExportRefreshReturnsExistingWhenNoHeader(): void
    {
        // Malformed existing data (no array header) is returned untouched.
        $existing = ['not-an-array-row'];

        $this->ruleFactory->expects($this->never())->method('create');

        $rows = $this->component->export(new ExportContext($existing, false));

        $this->assertSame($existing, $rows);
    }

    /**
     * Source rows: header (column codes) at index 0, then one data row.
     *
     * @return array<int, array<int, string>>
     */
    private function sourceRows(): array
    {
        return [
            0 => $this->header(),
            1 => ['VAT20', 'standard-rate', 'Retail Customer', 'Taxable Goods', '0', '0', '1'],
        ];
    }

    /**
     * Source rows carrying a `remove` column set truthy for the single data row.
     * The other columns are left empty: a removal must not depend on (or resolve)
     * rate/tax-class values.
     *
     * @return array<int, array<int, string>>
     */
    private function removalRows(): array
    {
        return [
            0 => $this->headerWithRemove(),
            1 => ['VAT20', '', '', '', '', '', '', '1'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function headerWithRemove(): array
    {
        return [...$this->header(), 'remove'];
    }

    /**
     * @return array<int, string>
     */
    private function header(): array
    {
        return [
            'code',
            'tax_rate_ids',
            'customer_tax_class_ids',
            'product_tax_class_ids',
            'priority',
            'calculate_subtotal',
            'position',
        ];
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function execute(
        array $rows,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext('test.csv', $mode, 'test', $dryRun, static fn (): array => $rows);

        return $this->component->execute($context);
    }

    /**
     * Make every rateFactory->create()->getCollection() chain resolve to a rate with $id.
     */
    private function givenRateLookupReturnsId(int $id): void
    {
        $rate = $this->createMock(Rate::class);
        $rate->method('getId')->willReturn($id);

        $collection = $this->createMock(\Magento\Tax\Model\ResourceModel\Calculation\Rate\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('load')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($rate);

        $rateModel = $this->createMock(Rate::class);
        $rateModel->method('getCollection')->willReturn($collection);

        $this->rateFactory->method('create')->willReturn($rateModel);
    }

    /**
     * Make every classModelFactory->create()->getCollection() chain resolve to a class with $id.
     */
    private function givenTaxClassLookupReturnsId(int $id): void
    {
        $class = $this->createMock(ClassModel::class);
        $class->method('getId')->willReturn($id);

        $collection = $this->createMock(\Magento\Tax\Model\ResourceModel\TaxClass\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($class);

        $classModel = $this->createMock(ClassModel::class);
        $classModel->method('getCollection')->willReturn($collection);
        $classModel->method('setClassName')->willReturnSelf();
        $classModel->method('setClassType')->willReturnSelf();
        $classModel->method('getId')->willReturn($id);

        $this->classModelFactory->method('create')->willReturn($classModel);
    }

    /**
     * Make the ruleFactory->create()->getCollection() lookup resolve to an existing rule
     * with $existingId (0 means "no existing rule"). Returns the model the component
     * will populate and save (the freshly-created one for create, the existing one for update).
     */
    private function givenRuleLookup(int $existingId): Rule&MockObject
    {
        $existing = $this->createMock(Rule::class);
        $existing->method('getId')->willReturn($existingId);

        $collection = $this->createMock(\Magento\Tax\Model\ResourceModel\Calculation\Rule\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($existing);

        $created = $this->createMock(Rule::class);
        $created->method('getCollection')->willReturn($collection);

        $this->ruleFactory->method('create')->willReturn($created);

        return $existingId ? $existing : $created;
    }

    private function ruleWithId(int $id): Rule&MockObject
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($id);

        return $rule;
    }

    private function fullyLoadedRule(int $id, string $code): Rule&MockObject
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($id);
        $rule->method('getCode')->willReturn($code);
        $rule->method('getTaxRateIds')->willReturn([5]);
        $rule->method('getCustomerTaxClassIds')->willReturn([3]);
        $rule->method('getProductTaxClassIds')->willReturn([]);
        $rule->method('getPriority')->willReturn(0);
        $rule->method('getCalculateSubtotal')->willReturn(0);
        $rule->method('getPosition')->willReturn(1);

        return $rule;
    }

    private function givenRateLoadById(int $id, string $code): void
    {
        // rateCodesFromIds() calls rateFactory->create()->load($id)->getCode().
        $rate = $this->createMock(Rate::class);
        $rate->method('load')->willReturnSelf();
        $rate->method('getId')->willReturn($id);
        $rate->method('getCode')->willReturn($code);

        $this->rateFactory->method('create')->willReturn($rate);
    }

    private function givenClassLoadById(int $id, string $name): void
    {
        $class = $this->createMock(ClassModel::class);
        $class->method('getId')->willReturn($id);
        $class->method('getClassName')->willReturn($name);

        $this->classModelFactory->method('create')->willReturn($class);
        $this->taxClassResource->method('load')->willReturnSelf();
    }
}
