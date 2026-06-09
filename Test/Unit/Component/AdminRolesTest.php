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
use Magebit\Configurator\Component\AdminRoles;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Authorization\Model\ResourceModel\Role as RoleResource;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\Rules;
use Magento\Authorization\Model\RulesFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminRolesTest extends TestCase
{
    private RoleFactory&MockObject $roleFactory;
    private RoleResource&MockObject $roleResource;
    private RulesFactory&MockObject $rulesFactory;
    private LoggerInterface&MockObject $log;
    private AdminRoles $component;

    protected function setUp(): void
    {
        $this->roleFactory = $this->getMockBuilder(RoleFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->roleResource = $this->createMock(RoleResource::class);
        $this->rulesFactory = $this->getMockBuilder(RulesFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->log = $this->createMock(LoggerInterface::class);

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new AdminRoles(
            $this->roleFactory,
            $this->roleResource,
            $this->rulesFactory,
            $this->log,
            $gate
        );
    }

    public function testCreatesRoleWhenItDoesNotExist(): void
    {
        // No existing role (id 0); a fresh role is built and saved.
        $role = $this->givenRoleFromFactory(0);
        $role->expects($this->once())->method('setRoleName')->with('Editors')->willReturnSelf();
        $role->method('setParentId')->willReturnSelf();
        $role->method('setRoleType')->willReturnSelf();
        $role->method('setUserType')->willReturnSelf();
        $role->method('setSortOrder')->willReturnSelf();
        $role->method('getId')->willReturn(7); // id assigned by the resource save

        $this->roleResource->expects($this->once())->method('save')->with($role);

        $rules = $this->givenRules();
        $rules->expects($this->once())->method('saveRel');

        $result = $this->execute([
            ['name' => 'Editors', 'resources' => ['Magento_Backend::all']],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingRole(): void
    {
        // Existing role -> create mode leaves it (and its resources) untouched.
        $this->givenRoleFromFactory(42);

        $this->roleResource->expects($this->never())->method('save');
        $this->rulesFactory->expects($this->never())->method('create');

        $result = $this->execute([
            ['name' => 'Editors', 'resources' => ['Magento_Backend::all']],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingRoleResources(): void
    {
        // Existing role in maintain mode -> reconcile its ACL resources, no role row saved.
        $this->givenRoleFromFactory(42);

        $this->roleResource->expects($this->never())->method('save');

        $rules = $this->givenRules();
        $rules->expects($this->once())->method('setRoleId')->with(42)->willReturnSelf();
        $rules->expects($this->once())->method('setResources')->with(['Magento_Backend::all'])->willReturnSelf();
        $rules->expects($this->once())->method('saveRel');

        $result = $this->execute([
            ['name' => 'Editors', 'resources' => ['Magento_Backend::all']],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        // Create scenario under dry-run: intent recorded, nothing written.
        $this->givenRoleFromFactory(0);

        $this->roleResource->expects($this->never())->method('save');
        $this->rulesFactory->expects($this->never())->method('create');

        $result = $this->execute([
            ['name' => 'Editors', 'resources' => ['Magento_Backend::all']],
        ], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testRemovesExistingRole(): void
    {
        // Existing role flagged remove -> deleted once via the resource, no save, no rules.
        $role = $this->givenRoleFromFactory(42);

        $this->roleResource->expects($this->never())->method('save');
        $this->rulesFactory->expects($this->never())->method('create');
        $this->roleResource->expects($this->once())->method('delete')->with($this->isInstanceOf(Role::class));

        $result = $this->execute([
            ['name' => 'Editors', 'remove' => true],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testRemoveAbsentRoleIsSkipped(): void
    {
        // No existing role (id 0) flagged remove -> nothing deleted, recorded as skipped.
        $this->givenRoleFromFactory(0);

        $this->roleResource->expects($this->never())->method('save');
        $this->roleResource->expects($this->never())->method('delete');

        $result = $this->execute([
            ['name' => 'Editors', 'remove' => true],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunRemoveDoesNotDelete(): void
    {
        // Existing role flagged remove under dry-run: intent recorded, nothing deleted.
        $this->givenRoleFromFactory(42);

        $this->roleResource->expects($this->never())->method('delete');
        $this->roleResource->expects($this->never())->method('save');

        $result = $this->execute([
            ['name' => 'Editors', 'remove' => true],
        ], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
    }

    public function testRecordsErrorWhenRoleNameMissing(): void
    {
        // Entry without a name is rejected; nothing is persisted.
        $this->roleResource->expects($this->never())->method('save');
        $this->roleFactory->expects($this->never())->method('create');

        $result = $this->execute([
            ['resources' => ['Magento_Backend::all']],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->roleResource->expects($this->never())->method('save');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
    }

    public function testFullExportDumpsEveryRole(): void
    {
        // Full export ignores existing data and dumps all admin roles with their resources.
        $role = $this->roleMock(['getRoleName', 'getId']);
        $role->method('getRoleName')->willReturn('Editors');
        $role->method('getId')->willReturn(7);

        $roleCollection = $this->createMock(\Magento\Authorization\Model\ResourceModel\Role\Collection::class);
        $roleCollection->method('addFieldToFilter')->willReturnSelf();
        $roleCollection->method('getIterator')->willReturn(new \ArrayIterator([$role]));
        $roleModel = $this->roleMock(['getCollection']);
        $roleModel->method('getCollection')->willReturn($roleCollection);
        $this->roleFactory->method('create')->willReturn($roleModel);

        // Rules collection yields one allowed resource row.
        $rulesModel = $this->givenRulesWithResources(['Magento_Backend::all']);
        $this->rulesFactory->method('create')->willReturn($rulesModel);

        $exported = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('adminroles', $exported);
        $this->assertSame('Editors', $exported['adminroles'][0]['name']);
        $this->assertSame(['Magento_Backend::all'], $exported['adminroles'][0]['resources']);
    }

    public function testRefreshExportRewritesOnlyTrackedRoles(): void
    {
        // Refresh mode rebuilds resources for tracked roles found in the DB, preserving other keys.
        $lookup = $this->roleMock(['getId']);
        $lookup->method('getId')->willReturn(7);

        $collection = $this->createMock(\Magento\Authorization\Model\ResourceModel\Role\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($lookup);

        $roleModel = $this->roleMock(['getCollection']);
        $roleModel->method('getCollection')->willReturn($collection);
        $this->roleFactory->method('create')->willReturn($roleModel);

        $rulesModel = $this->givenRulesWithResources(['Magento_Backend::content']);
        $this->rulesFactory->method('create')->willReturn($rulesModel);

        $existing = ['adminroles' => [
            ['name' => 'Editors', 'version' => 2, 'resources' => ['stale']],
        ]];

        $exported = $this->component->export(new ExportContext($existing, false));

        $this->assertCount(1, $exported['adminroles']);
        // version preserved, resources rebuilt from the DB.
        $this->assertSame(2, $exported['adminroles'][0]['version']);
        $this->assertSame(['Magento_Backend::content'], $exported['adminroles'][0]['resources']);
    }

    public function testRefreshExportKeepsUntrackedEntriesUnchanged(): void
    {
        // An entry without a recognisable name is preserved verbatim and the DB is never queried.
        $this->roleFactory->expects($this->never())->method('create');

        $existing = ['adminroles' => [
            ['note' => 'free-form entry without a name'],
        ]];

        $exported = $this->component->export(new ExportContext($existing, false));

        $this->assertSame($existing, $exported);
    }

    /**
     * @param array $adminRoles value of the `adminroles` node
     * @param array|null $rawData full source override (bypasses $adminRoles)
     */
    private function execute(
        array $adminRoles,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['adminroles' => $adminRoles];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * Wire the role factory so create() yields a Role whose name lookup resolves to
     * a first item with the given id (0 => no existing role).
     */
    private function givenRoleFromFactory(int $existingId): Role&MockObject
    {
        $existing = $this->roleMock(['getId', 'getRoleName']);
        $existing->method('getId')->willReturn($existingId);
        $existing->method('getRoleName')->willReturn('Editors');

        $collection = $this->createMock(\Magento\Authorization\Model\ResourceModel\Role\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($existing);

        $role = $this->roleMock([
            'getCollection',
            'getId',
            'getRoleName',
            'setRoleName',
            'setParentId',
            'setRoleType',
            'setUserType',
            'setSortOrder',
        ]);
        $role->method('getCollection')->willReturn($collection);
        $role->method('getRoleName')->willReturn('Editors');
        $this->roleFactory->method('create')->willReturn($role);

        return $role;
    }

    /**
     * Build a Role mock. AbstractModel data getters/setters such as getRoleName,
     * setRoleName, setParentId, setRoleType, setUserType and setSortOrder are magic
     * (@method) methods, so they must be declared via addMethods(); declared methods
     * such as getId and getCollection go through onlyMethods().
     *
     * @param string[] $methods
     */
    private function roleMock(array $methods): Role&MockObject
    {
        $magic = ['getRoleName', 'setRoleName', 'setParentId', 'setRoleType', 'setUserType', 'setSortOrder'];

        $addMethods = array_values(array_intersect($methods, $magic));
        $onlyMethods = array_values(array_diff($methods, $magic));

        $builder = $this->getMockBuilder(Role::class)->disableOriginalConstructor();
        if ($onlyMethods !== []) {
            $builder->onlyMethods($onlyMethods);
        }
        if ($addMethods !== []) {
            $builder->addMethods($addMethods);
        }

        return $builder->getMock();
    }

    private function givenRules(): Rules&MockObject
    {
        // setRoleId/setResources are magic (@method) setters; saveRel is declared.
        $rules = $this->getMockBuilder(Rules::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['saveRel'])
            ->addMethods(['setRoleId', 'setResources'])
            ->getMock();
        $rules->method('setRoleId')->willReturnSelf();
        $rules->method('setResources')->willReturnSelf();
        $this->rulesFactory->method('create')->willReturn($rules);

        return $rules;
    }

    /**
     * A Rules model whose `allow` rules collection iterates the given resource ids.
     *
     * @param string[] $resources
     */
    private function givenRulesWithResources(array $resources): Rules&MockObject
    {
        $rows = [];
        foreach ($resources as $resourceId) {
            $rule = $this->getMockBuilder(Rules::class)
                ->disableOriginalConstructor()
                ->addMethods(['getResourceId'])
                ->getMock();
            $rule->method('getResourceId')->willReturn($resourceId);
            $rows[] = $rule;
        }

        $collection = $this->createMock(\Magento\Authorization\Model\ResourceModel\Rules\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        $rules = $this->createMock(Rules::class);
        $rules->method('getCollection')->willReturn($collection);

        return $rules;
    }
}
