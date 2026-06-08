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
use Magebit\Configurator\Component\CustomerGroups;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
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

        $this->component = new CustomerGroups(
            $this->groupRepository,
            $this->groupFactory,
            $this->taxClassRepository,
            $this->searchCriteriaBuilder,
            $this->log
        );
    }

    public function testCreatesGroupWhenItDoesNotExist(): void
    {
        $this->givenTaxClassExists(3);
        $this->givenGroupCount(0);

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

    public function testSkipsExistingGroup(): void
    {
        $this->givenTaxClassExists(3);
        $this->givenGroupCount(1);

        $this->groupFactory->expects($this->never())->method('create');
        $this->groupRepository->expects($this->never())->method('save');

        $result = $this->execute([
            ['taxclass' => 'Retail Customer', 'groups' => [['name' => 'VIP']]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenTaxClassExists(3);
        $this->givenGroupCount(0);
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
        $this->givenGroupCount(0);
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
    private function execute(array $customerGroups, bool $dryRun = false, ?array $rawData = null): ComponentResult
    {
        $data = $rawData ?? ['customergroups' => $customerGroups];
        $context = new ComponentContext('test.yaml', ComponentMode::Create, 'test', $dryRun, static fn (): array => $data);

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

    private function givenGroupCount(int $count): void
    {
        $results = $this->createMock(GroupSearchResultsInterface::class);
        $results->method('getTotalCount')->willReturn($count);
        $this->groupRepository->method('getList')->willReturn($results);
    }
}
