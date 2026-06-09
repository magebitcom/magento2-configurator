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
use Magebit\Configurator\Component\OrderStatuses;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderStatusesTest extends TestCase
{
    private StatusFactory&MockObject $statusFactory;
    private StatusResourceFactory&MockObject $statusResourceFactory;
    private StatusResource&MockObject $statusResource;
    private LoggerInterface&MockObject $log;
    private VersionManagementInterface&MockObject $versionManagement;
    private OrderStatuses $component;

    protected function setUp(): void
    {
        $this->statusFactory = $this->getMockBuilder(StatusFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->statusResourceFactory = $this->getMockBuilder(StatusResourceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        // A single shared resource mock is yielded for every create() call.
        $this->statusResource = $this->createMock(StatusResource::class);
        $this->statusResourceFactory->method('create')->willReturn($this->statusResource);

        $this->log = $this->createMock(LoggerInterface::class);

        // Real gate over a version store that always reports "not newer".
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);

        $this->component = new OrderStatuses(
            $this->statusFactory,
            $this->statusResourceFactory,
            $this->log,
            $gate
        );
    }

    public function testCreatesStatusWhenItDoesNotExist(): void
    {
        // load() leaves the model empty -> getStatus() is null -> does not exist.
        $status = $this->givenStatusModel(null);
        $status->expects($this->exactly(2))->method('setData')->willReturnSelf();
        $status->expects($this->once())->method('assignState')->with('processing', false, true);

        $this->statusResource->expects($this->once())->method('save')->with($status);

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [['code' => 'awaiting_pick', 'name' => 'Awaiting Pick']]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingStatus(): void
    {
        // getStatus() returns the loaded code -> exists -> create mode skips it.
        $status = $this->givenStatusModel('awaiting_pick');

        $this->statusResource->expects($this->never())->method('save');
        $status->expects($this->never())->method('assignState');

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [['code' => 'awaiting_pick', 'name' => 'Awaiting Pick']]],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingStatus(): void
    {
        // Exists, no version bump, maintain mode -> update (the gate's decision table).
        $status = $this->givenStatusModel('awaiting_pick');
        $status->expects($this->exactly(2))->method('setData')->willReturnSelf();
        $status->expects($this->once())->method('assignState')->with('processing', false, true);

        $this->statusResource->expects($this->once())->method('save')->with($status);

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [['code' => 'awaiting_pick', 'name' => 'Awaiting Pick']]],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testVersionBumpForcesUpdateInCreateMode(): void
    {
        // Exists in create mode, but a newer declared version forces an update.
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(true);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);
        $this->component = new OrderStatuses(
            $this->statusFactory,
            $this->statusResourceFactory,
            $this->log,
            $gate
        );

        $status = $this->givenStatusModel('awaiting_pick');
        $status->method('setData')->willReturnSelf();

        $this->versionManagement->expects($this->once())->method('setVersion')->with('order_statuses_awaiting_pick', 5);
        $this->statusResource->expects($this->once())->method('save')->with($status);

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [
                ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick', 'version' => 5],
            ]],
        ]);

        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $status = $this->givenStatusModel(null);
        $status->expects($this->never())->method('setData');
        $status->expects($this->never())->method('assignState');

        $this->statusResource->expects($this->never())->method('save');
        $this->versionManagement->expects($this->never())->method('setVersion');

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [['code' => 'awaiting_pick', 'name' => 'Awaiting Pick']]],
        ], true);

        // Dry run still records the intended create.
        $this->assertSame(1, $result->getCreated());
    }

    public function testDoubleRunDoesNotReCreateStatus(): void
    {
        // First run: status missing -> created. Second run (same gate, create mode):
        // status now exists -> protected, so no duplicate-key save occurs.
        $missing = $this->newStatusMock();
        $missing->method('getStatus')->willReturn(null);
        $missing->method('setData')->willReturnSelf();
        $existing = $this->newStatusMock();
        $existing->method('getStatus')->willReturn('awaiting_pick');

        // First create() yields the missing model, second yields the now-existing one.
        $this->statusFactory->method('create')->willReturnOnConsecutiveCalls($missing, $existing);

        $this->statusResource->expects($this->once())->method('save')->with($missing);

        $rows = [
            ['state' => 'processing', 'statuses' => [['code' => 'awaiting_pick', 'name' => 'Awaiting Pick']]],
        ];

        $first = $this->execute($rows);
        $second = $this->execute($rows);

        $this->assertSame(1, $first->getCreated());
        $this->assertSame(0, $second->getCreated());
        $this->assertSame(1, $second->getSkipped());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->statusResource->expects($this->never())->method('save');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRemoveDeletesExistingStatus(): void
    {
        // Exists -> delete() is called once, no save occurs.
        $status = $this->givenStatusModel('awaiting_pick');
        $status->expects($this->never())->method('setData');
        $status->expects($this->never())->method('assignState');

        $this->statusResource->expects($this->never())->method('save');
        $this->statusResource->expects($this->once())->method('delete')->with($status);

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [
                ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick', 'remove' => true],
            ]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemoveAbsentStatusIsSkipped(): void
    {
        // load() leaves the model empty -> getStatus() null -> does not exist.
        $status = $this->givenStatusModel(null);
        $status->expects($this->never())->method('setData');

        $this->statusResource->expects($this->never())->method('save');
        $this->statusResource->expects($this->never())->method('delete');

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [
                ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick', 'remove' => true],
            ]],
        ]);

        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testRemoveDryRunDeletesNothing(): void
    {
        $status = $this->givenStatusModel('awaiting_pick');

        $this->statusResource->expects($this->never())->method('save');
        $this->statusResource->expects($this->never())->method('delete');

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [
                ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick', 'remove' => true],
            ]],
        ], true);

        // Dry run still records the intended removal.
        $this->assertSame(1, $result->getRemoved());
    }

    public function testRemoveAssignedStatusIsSkippedWhenDeleteFails(): void
    {
        // A status still assigned to a state cannot be deleted; the resource
        // throws and the run records a skip rather than crashing.
        $status = $this->givenStatusModel('awaiting_pick');

        $this->statusResource->expects($this->never())->method('save');
        $this->statusResource->expects($this->once())->method('delete')->with($status)
            ->willThrowException(new \Exception('Status is assigned to a state'));

        $result = $this->execute([
            ['state' => 'processing', 'statuses' => [
                ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick', 'remove' => true],
            ]],
        ]);

        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testFullExportGroupsStatusesByState(): void
    {
        $this->givenStatusCollection([
            ['code' => 'pending', 'label' => 'Pending', 'state' => 'new'],
            ['code' => 'awaiting_pick', 'label' => 'Awaiting Pick', 'state' => 'processing'],
            ['code' => 'packed', 'label' => 'Packed', 'state' => 'processing'],
            // Unassigned status: no parent state -> skipped from the source format.
            ['code' => 'orphan', 'label' => 'Orphan', 'state' => null],
        ]);

        $out = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('order_statuses', $out);
        $sets = $out['order_statuses'];
        $this->assertCount(2, $sets);

        $byState = [];
        foreach ($sets as $set) {
            $byState[$set['state']] = $set['statuses'];
        }

        $this->assertSame([['code' => 'pending', 'name' => 'Pending']], $byState['new']);
        $this->assertSame(
            [
                ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick'],
                ['code' => 'packed', 'name' => 'Packed'],
            ],
            $byState['processing']
        );
    }

    public function testFullExportRespectsCodeFilter(): void
    {
        $this->givenStatusCollection([
            ['code' => 'pending', 'label' => 'Pending', 'state' => 'new'],
            ['code' => 'awaiting_pick', 'label' => 'Awaiting Pick', 'state' => 'processing'],
        ]);

        $out = $this->component->export(new ExportContext([], true, 'await'));

        $this->assertCount(1, $out['order_statuses']);
        $this->assertSame('processing', $out['order_statuses'][0]['state']);
        $this->assertSame(
            [['code' => 'awaiting_pick', 'name' => 'Awaiting Pick']],
            $out['order_statuses'][0]['statuses']
        );
    }

    public function testRefreshRewritesTrackedNamesAndPreservesVersion(): void
    {
        $this->givenStatusCollection([
            ['code' => 'awaiting_pick', 'label' => 'Renamed Pick', 'state' => 'processing'],
        ]);

        $existing = [
            'order_statuses' => [
                ['state' => 'processing', 'statuses' => [
                    ['code' => 'awaiting_pick', 'name' => 'Old Name', 'version' => 2],
                ]],
            ],
        ];

        $out = $this->component->export(new ExportContext($existing, false));

        $entry = $out['order_statuses'][0]['statuses'][0];
        $this->assertSame('Renamed Pick', $entry['name']);
        // Structural keys such as version survive a refresh.
        $this->assertSame(2, $entry['version']);
    }

    public function testRefreshMovesStatusToReassignedState(): void
    {
        // Admin moved the status from "processing" to "complete" in the DB.
        $this->givenStatusCollection([
            ['code' => 'awaiting_pick', 'label' => 'Awaiting Pick', 'state' => 'complete'],
        ]);

        $existing = [
            'order_statuses' => [
                ['state' => 'processing', 'statuses' => [
                    ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick'],
                ]],
            ],
        ];

        $out = $this->component->export(new ExportContext($existing, false));

        // The now-empty processing set is pruned; the status lives under complete.
        $this->assertCount(1, $out['order_statuses']);
        $this->assertSame('complete', $out['order_statuses'][0]['state']);
        $this->assertSame('awaiting_pick', $out['order_statuses'][0]['statuses'][0]['code']);
    }

    public function testRefreshLeavesTrackedEntryWhenRecordGone(): void
    {
        // DB has no matching record; the tracked entry must be kept untouched.
        $this->givenStatusCollection([
            ['code' => 'something_else', 'label' => 'Else', 'state' => 'new'],
        ]);

        $existing = [
            'order_statuses' => [
                ['state' => 'processing', 'statuses' => [
                    ['code' => 'awaiting_pick', 'name' => 'Awaiting Pick'],
                ]],
            ],
        ];

        $out = $this->component->export(new ExportContext($existing, false));

        $this->assertSame('Awaiting Pick', $out['order_statuses'][0]['statuses'][0]['name']);
    }

    /**
     * @param array $orderStatuses value of the `order_statuses` node
     * @param array|null $rawData full source override (bypasses $orderStatuses)
     */
    private function execute(
        array $orderStatuses,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['order_statuses' => $orderStatuses];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * A status model whose getStatus() returns $loadedCode after the resource
     * load() runs ($loadedCode null => the status does not yet exist).
     */
    private function givenStatusModel(?string $loadedCode): Status&MockObject
    {
        $status = $this->newStatusMock();
        $status->method('getStatus')->willReturn($loadedCode);
        $this->statusFactory->method('create')->willReturn($status);

        return $status;
    }

    /**
     * Build a Status mock. getStatus/getLabel/getState are magic DataObject
     * getters (not declared on the class), so they must be added explicitly;
     * setData/assignState/getResourceCollection are real declared methods.
     */
    private function newStatusMock(): Status&MockObject
    {
        return $this->getMockBuilder(Status::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'assignState', 'getResourceCollection'])
            ->addMethods(['getStatus', 'getLabel', 'getState'])
            ->getMock();
    }

    /**
     * Stub the status resource collection loadStatusMap() reads during export.
     *
     * @param array<int, array{code: string, label: string, state: string|null}> $rows
     */
    private function givenStatusCollection(array $rows): void
    {
        $items = [];
        foreach ($rows as $row) {
            $item = $this->newStatusMock();
            $item->method('getStatus')->willReturn($row['code']);
            $item->method('getLabel')->willReturn($row['label']);
            $item->method('getState')->willReturn($row['state']);
            $items[] = $item;
        }

        $collection = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Order\Status\Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['joinStates', 'getIterator'])
            ->getMock();
        $collection->method('joinStates')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $status = $this->newStatusMock();
        $status->method('getResourceCollection')->willReturn($collection);
        $this->statusFactory->method('create')->willReturn($status);
    }
}
