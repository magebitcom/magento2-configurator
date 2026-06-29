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
use Magebit\Configurator\Component\Customers;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Import\Importer;
use Magebit\Configurator\Model\Import\ImporterFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\Data\GroupSearchResultsInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Indexer\Model\IndexerFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomersTest extends TestCase
{
    private ImporterFactory&MockObject $importerFactory;
    private Importer&MockObject $importer;
    private GroupRepositoryInterface&MockObject $groupRepository;
    private GroupManagementInterface&MockObject $groupManagement;
    private SearchCriteriaBuilder&MockObject $criteriaBuilder;
    private IndexerFactory&MockObject $indexerFactory;
    private LoggerInterface&MockObject $log;
    private CustomerCollectionFactory&MockObject $customerCollectionFactory;
    private Customers $component;

    protected function setUp(): void
    {
        $this->importer = $this->createMock(Importer::class);
        $this->importerFactory = $this->getMockBuilder(ImporterFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->importerFactory->method('create')->willReturn($this->importer);

        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->groupManagement = $this->createMock(GroupManagementInterface::class);
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->indexerFactory = $this->getMockBuilder(IndexerFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('load')->willReturnSelf();
        $this->indexerFactory->method('create')->willReturn($indexer);

        $this->log = $this->createMock(LoggerInterface::class);

        $this->customerCollectionFactory = $this->getMockBuilder(CustomerCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->component = new Customers(
            $this->importerFactory,
            $this->groupRepository,
            $this->groupManagement,
            $this->criteriaBuilder,
            $this->indexerFactory,
            $this->log,
            $this->customerCollectionFactory
        );
    }

    public function testImportsNewCustomersWhenNoneExist(): void
    {
        $this->givenValidGroups([1]);
        $this->givenExistingCustomerEmails([]);

        $this->importer->expects($this->once())->method('setEntityCode')->with('customer_composite');
        $this->importer->expects($this->once())->method('setBehavior');
        $this->importer->expects($this->once())->method('processImport');

        $result = $this->execute($this->givenSource([
            ['new@example.com', 'base', 'default', '1'],
        ]));

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeSkipsRowsForCustomersThatAlreadyExist(): void
    {
        $this->givenValidGroups([1]);
        // The existing-email lookup is case-insensitive.
        $this->givenExistingCustomerEmails(['exists@example.com']);

        $this->importer->expects($this->never())->method('processImport');

        $result = $this->execute($this->givenSource([
            ['EXISTS@example.com', 'base', 'default', '1'],
        ]));

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testCreateModeKeepsTrailingAddressRowsWithTheirCustomer(): void
    {
        $this->givenValidGroups([1]);
        // Only the second customer exists; the first (and its empty-email
        // address row) must survive while the existing one is dropped wholesale.
        $this->givenExistingCustomerEmails(['old@example.com']);

        $captured = null;
        $this->importer->expects($this->once())->method('processImport')
            ->willReturnCallback(function (array $rows) use (&$captured): void {
                $captured = $rows;
            });

        $result = $this->execute($this->givenSource([
            ['fresh@example.com', 'base', 'default', '1'],
            ['', 'base', 'default', '1'],
            ['old@example.com', 'base', 'default', '1'],
        ]));

        // Two kept rows (the new customer + its address), one existing customer skipped.
        $this->assertSame(2, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
        $this->assertCount(2, (array) $captured);
    }

    public function testInvalidGroupIdFallsBackToDefault(): void
    {
        $this->givenValidGroups([1]);
        $this->givenExistingCustomerEmails([]);
        $this->givenDefaultGroupId(7);

        $captured = null;
        $this->importer->method('processImport')
            ->willReturnCallback(function (array $rows) use (&$captured): void {
                $captured = $rows;
            });

        $result = $this->execute($this->givenSource([
            ['new@example.com', 'base', 'default', '999'],
        ]));

        $this->assertSame(1, $result->getCreated());
        $this->assertSame(7, $captured[0][Customers::CUSTOMER_GROUP_HEADER]);
    }

    public function testMaintainModeReimportsExistingCustomersWithoutDropping(): void
    {
        $this->givenValidGroups([1]);
        // In maintain mode dropExistingCustomers is bypassed, so the collection
        // factory must never be touched and every row is imported.
        $this->customerCollectionFactory->expects($this->never())->method('create');

        $this->importer->expects($this->once())->method('processImport');

        $result = $this->execute(
            $this->givenSource([['exists@example.com', 'base', 'default', '1']]),
            false,
            ComponentMode::Maintain
        );

        $this->assertSame(1, $result->getCreated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testVersionBumpForcesFullReimportInCreateMode(): void
    {
        $this->givenValidGroups([1]);
        // A non-null version short-circuits the existing-customer drop.
        $this->customerCollectionFactory->expects($this->never())->method('create');

        $this->importer->expects($this->once())->method('processImport');

        $result = $this->execute(
            $this->givenSource([['exists@example.com', 'base', 'default', '1']]),
            false,
            ComponentMode::Create,
            5
        );

        $this->assertSame(1, $result->getCreated());
    }

    public function testDryRunDoesNotImportOrReindex(): void
    {
        $this->givenValidGroups([1]);
        $this->givenExistingCustomerEmails([]);

        $this->importerFactory->expects($this->never())->method('create');
        $this->indexerFactory->expects($this->never())->method('create');

        $result = $this->execute(
            $this->givenSource([['new@example.com', 'base', 'default', '1']]),
            true
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testErrorsWhenNoDataRows(): void
    {
        $this->importerFactory->expects($this->never())->method('create');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testErrorsWhenRequiredColumnMissing(): void
    {
        $this->importerFactory->expects($this->never())->method('create');

        // Header lacks the required `_store` column.
        $result = $this->execute([
            0 => ['email', '_website'],
            1 => ['new@example.com', 'base'],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testNoImportWhenAllRowsBelongToExistingCustomers(): void
    {
        $this->givenValidGroups([1]);
        $this->givenExistingCustomerEmails(['a@example.com', 'b@example.com']);

        $this->importer->expects($this->never())->method('processImport');

        $result = $this->execute($this->givenSource([
            ['a@example.com', 'base', 'default', '1'],
            ['b@example.com', 'base', 'default', '1'],
        ]));

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(2, $result->getSkipped());
    }

    /**
     * @param array $data full source array ($data[0] is the header row)
     */
    private function execute(
        array $data,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create,
        ?int $version = null
    ): ComponentResult {
        $context = new ComponentContext('test.csv', $mode, 'test', $dryRun, static fn (): array => $data, $version);

        return $this->component->execute($context);
    }

    /**
     * Build a source array with the standard header and the given numerically
     * indexed data rows (matching header positions: email, _website, _store, group_id).
     *
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<int, string>>
     */
    private function givenSource(array $rows): array
    {
        $data = [0 => ['email', '_website', '_store', 'group_id']];
        $index = 1;
        foreach ($rows as $row) {
            $data[$index++] = $row;
        }

        return $data;
    }

    /**
     * @param int[] $groupIds
     */
    private function givenValidGroups(array $groupIds): void
    {
        $items = [];
        foreach ($groupIds as $id) {
            $group = $this->createMock(GroupInterface::class);
            $group->method('getId')->willReturn($id);
            $items[] = $group;
        }

        $results = $this->createMock(GroupSearchResultsInterface::class);
        $results->method('getItems')->willReturn($items);
        $this->groupRepository->method('getList')->willReturn($results);
    }

    private function givenDefaultGroupId(int $id): void
    {
        $default = $this->createMock(GroupInterface::class);
        $default->method('getId')->willReturn($id);
        $this->groupManagement->method('getDefaultGroup')->willReturn($default);
    }

    /**
     * Stub the customer collection lookup with the set of emails that already exist.
     *
     * @param string[] $existingEmails
     */
    private function givenExistingCustomerEmails(array $existingEmails): void
    {
        $customers = [];
        foreach ($existingEmails as $email) {
            $customer = $this->getMockBuilder(Customer::class)
                ->disableOriginalConstructor()
                ->addMethods(['getEmail'])
                ->getMock();
            $customer->method('getEmail')->willReturn($email);
            $customers[] = $customer;
        }

        $collection = $this->createMock(CustomerCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($customers));

        $this->customerCollectionFactory->method('create')->willReturn($collection);
    }
}
