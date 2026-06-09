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
use Magebit\Configurator\Component\CustomerGroups;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\Data\GroupSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Tax\Api\Data\TaxClassInterface;
use Magento\Tax\Api\Data\TaxClassSearchResultsInterface;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerGroupsTest extends TestCase
{
    private GroupRepositoryInterface&MockObject $groupRepository;
    private GroupInterfaceFactory&MockObject $groupFactory;
    private TaxClassRepositoryInterface&MockObject $taxClassRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private LoggerInterface&MockObject $log;
    private CustomerGroups $component;

    protected function setUp(): void
    {
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->groupFactory = $this->getMockBuilder(GroupInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->taxClassRepository = $this->createMock(TaxClassRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->log = $this->createMock(LoggerInterface::class);

        // Fluent builder: addFilter() chains, create() yields a criteria.
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new CustomerGroups(
            $this->groupRepository,
            $this->groupFactory,
            $this->taxClassRepository,
            $this->searchCriteriaBuilder,
            $this->log,
            $gate
        );
    }

    public function testCreatesGroupWhenItDoesNotExist(): void
    {
        $this->givenTaxClassExists(3);
        $this->givenNoExistingGroup();

        $group = $this->createMock(GroupInterface::class);
        $group->expects($this->once())->method('setCode')->with('VIP')->willReturnSelf();
        $group->expects($this->once())->method('setTaxClassId')->with(3)->willReturnSelf();
        $this->groupFactory->method('create')->willReturn($group);

        $this->groupRepository->expects($this->once())->method('save')->with($group);

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP']]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingGroup(): void
    {
        $this->givenTaxClassExists(3);
        // Existing group with a DIFFERENT tax class — create mode must still skip it.
        $this->givenExistingGroup(99);

        $this->groupFactory->expects($this->never())->method('create');
        $this->groupRepository->expects($this->never())->method('save');

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP']]],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingTaxClass(): void
    {
        $this->givenTaxClassExists(3);
        $existing = $this->givenExistingGroup(99);

        // Maintain mode re-points the existing group's tax class to the configured one.
        $existing->expects($this->once())->method('setTaxClassId')->with(3)->willReturnSelf();
        $existing->method('setCode')->willReturnSelf();
        $this->groupFactory->expects($this->never())->method('create');
        $this->groupRepository->expects($this->once())->method('save')->with($existing);

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP']]],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedGroup(): void
    {
        $this->givenTaxClassExists(3);
        // Existing group already on the configured tax class -> unchanged -> skip.
        $this->givenExistingGroup(3);

        $this->groupRepository->expects($this->never())->method('save');

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP']]],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenTaxClassExists(3);
        $this->givenNoExistingGroup();
        $this->groupFactory->method('create')->willReturn($this->createMock(GroupInterface::class));

        $this->groupRepository->expects($this->never())->method('save');

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP']]],
        ], true);

        $this->assertSame(1, $result->getCreated());
    }

    public function testSkipsAllGroupsWhenTaxClassMissing(): void
    {
        $this->givenTaxClassMissing();

        $this->groupRepository->expects($this->never())->method('save');

        $result = $this->execute([
            ['taxclass' => 'Does Not Exist', 'groups' => [['name' => 'VIP']]],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRejectsMissingAndOverlongNames(): void
    {
        $this->givenTaxClassExists(3);
        $this->givenNoExistingGroup();
        $this->groupFactory->method('create')->willReturn($this->createMock(GroupInterface::class));

        // Missing name + 33-char name are both rejected; the valid one is still saved.
        $this->groupRepository->expects($this->once())->method('save');

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [
                ['nope' => 'no name key'],
                ['name' => str_repeat('a', 33)],
                ['name' => 'Valid'],
            ]],
        ]);

        $this->assertSame(1, $result->getCreated());
        $this->assertGreaterThanOrEqual(2, count($result->getErrors()));
    }

    public function testRemovesExistingGroup(): void
    {
        $this->givenTaxClassExists(3);
        $existing = $this->givenExistingGroup(3);
        $existing->method('getId')->willReturn(5);

        $this->groupRepository->expects($this->never())->method('save');
        $this->groupRepository->expects($this->once())->method('deleteById')->with(5);

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP', 'remove' => true]]],
        ]);

        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getSkipped());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemoveAbsentGroupIsSkipped(): void
    {
        $this->givenTaxClassExists(3);
        $this->givenNoExistingGroup();

        $this->groupRepository->expects($this->never())->method('save');
        $this->groupRepository->expects($this->never())->method('deleteById');

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'Gone', 'remove' => true]]],
        ]);

        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunRemoveDoesNotDelete(): void
    {
        $this->givenTaxClassExists(3);
        $existing = $this->givenExistingGroup(3);
        $existing->method('getId')->willReturn(5);

        $this->groupRepository->expects($this->never())->method('deleteById');

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP', 'remove' => true]]],
        ], true);

        $this->assertSame(1, $result->getRemoved());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->groupRepository->expects($this->never())->method('save');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
    }

    /**
     * @param array $customerGroups value of the `customergroups` node
     * @param array|null $rawData full source override (bypasses $customerGroups)
     */
    private function execute(
        array $customerGroups,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['customergroups' => $customerGroups];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    private function givenTaxClassExists(int $classId): void
    {
        $taxClass = $this->createMock(TaxClassInterface::class);
        $taxClass->method('getClassId')->willReturn($classId);

        $results = $this->createMock(TaxClassSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$taxClass]);
        $this->taxClassRepository->method('getList')->willReturn($results);
    }

    private function givenTaxClassMissing(): void
    {
        $results = $this->createMock(TaxClassSearchResultsInterface::class);
        $results->method('getItems')->willReturn([]);
        $this->taxClassRepository->method('getList')->willReturn($results);
    }

    private function givenNoExistingGroup(): void
    {
        $results = $this->createMock(GroupSearchResultsInterface::class);
        $results->method('getItems')->willReturn([]);
        $this->groupRepository->method('getList')->willReturn($results);
    }

    private function givenExistingGroup(int $taxClassId): GroupInterface&MockObject
    {
        $group = $this->createMock(GroupInterface::class);
        $group->method('getTaxClassId')->willReturn($taxClassId);

        $results = $this->createMock(GroupSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$group]);
        $this->groupRepository->method('getList')->willReturn($results);

        return $group;
    }
}
