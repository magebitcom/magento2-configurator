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
use Magebit\Configurator\Component\AdminUsers;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\ResourceModel\Role\Collection as RoleCollection;
use Magento\Authorization\Model\RoleFactory;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\Collection as UserCollection;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminUsersTest extends TestCase
{
    private UserFactory&MockObject $userFactory;
    private UserResource&MockObject $userResource;
    private RoleFactory&MockObject $roleFactory;
    private LoggerInterface&MockObject $log;
    private AdminUsers $component;

    protected function setUp(): void
    {
        $this->userFactory = $this->getMockBuilder(UserFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->userResource = $this->createMock(UserResource::class);
        $this->roleFactory = $this->getMockBuilder(RoleFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->log = $this->createMock(LoggerInterface::class);

        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new AdminUsers(
            $this->userFactory,
            $this->userResource,
            $this->roleFactory,
            $this->log,
            $gate
        );
    }

    public function testCreatesUserWhenItDoesNotExist(): void
    {
        $this->givenRoleExists('Administrators', 1);

        // The user the component builds & saves; not yet existing in the DB.
        $user = $this->givenNewUser(exists: false);
        $user->expects($this->once())->method('setUserName')->with('jdoe')->willReturnSelf();
        $user->expects($this->once())->method('setPassword')->with('s3cret!')->willReturnSelf();
        $user->method('validate')->willReturn(true);

        $this->userResource->expects($this->once())->method('save')->with($user);

        $result = $this->execute([
            ['rolename' => 'Administrators', 'users' => [$this->userRow()]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingUser(): void
    {
        $this->givenRoleExists('Administrators', 1);
        // A user with this email already exists; create mode must leave it alone.
        $this->givenNewUser(exists: true);

        $this->userResource->expects($this->never())->method('save');

        $result = $this->execute([
            ['rolename' => 'Administrators', 'users' => [$this->userRow()]],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingUser(): void
    {
        $this->givenRoleExists('Administrators', 1);

        // Probe user reports an existing record; component reloads it for update.
        $probe = $this->userMock();
        $existing = $this->userMock();
        $this->givenUserLookup($probe, exists: true, existing: $existing);

        $loaded = $this->userMock();
        $loaded->method('load')->willReturnSelf();
        $this->makeFluentUser($loaded);
        $loaded->expects($this->never())->method('setPassword');
        $loaded->method('validate')->willReturn(true);

        // First create() yields the probe; second create()->load() yields the loaded user.
        $this->userFactory->method('create')
            ->willReturnOnConsecutiveCalls($probe, $loaded);

        $this->userResource->expects($this->once())->method('save')->with($loaded);

        $result = $this->execute([
            ['rolename' => 'Administrators', 'users' => [$this->userRow()]],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenRoleExists('Administrators', 1);
        $this->givenNewUser(exists: false);

        $this->userResource->expects($this->never())->method('save');

        $result = $this->execute([
            ['rolename' => 'Administrators', 'users' => [$this->userRow()]],
        ], true);

        // Intent is still recorded even though nothing is persisted.
        $this->assertSame(1, $result->getCreated());
    }

    public function testErrorWhenRoleDoesNotExist(): void
    {
        $this->givenRoleMissing('Ghosts');

        $this->userResource->expects($this->never())->method('save');

        $result = $this->execute([
            ['rolename' => 'Ghosts', 'users' => [$this->userRow()]],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRejectsUserMissingRequiredFields(): void
    {
        $this->givenRoleExists('Administrators', 1);

        $this->userResource->expects($this->never())->method('save');

        // Missing password (and others); never reaches a save.
        $result = $this->execute([
            ['rolename' => 'Administrators', 'users' => [
                ['username' => 'jdoe', 'firstname' => 'Jane'],
            ]],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
    }

    public function testFailedValidationRecordsErrorAndDoesNotSave(): void
    {
        $this->givenRoleExists('Administrators', 1);

        $user = $this->givenNewUser(exists: false);
        $user->method('validate')->willReturn(false);

        $this->userResource->expects($this->never())->method('save');

        $result = $this->execute([
            ['rolename' => 'Administrators', 'users' => [$this->userRow()]],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->userResource->expects($this->never())->method('save');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testFullExportGroupsUsersByRoleAndOmitsPassword(): void
    {
        $user = $this->userMock();
        $user->method('getUserName')->willReturn('jdoe');
        $user->method('getFirstName')->willReturn('Jane');
        $user->method('getLastName')->willReturn('Doe');
        $user->method('getEmail')->willReturn('jane@example.com');
        $user->method('getInterfaceLocale')->willReturn('en_US');
        $user->method('getRole')->willReturn(null);
        $user->method('getRoleId')->willReturn(1);

        $collection = $this->createMock(UserCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$user]));

        $collectionUser = $this->userMock();
        $collectionUser->method('getCollection')->willReturn($collection);

        // create() is called for the collection iteration and for each role resolve.
        $role = $this->roleMock();
        $role->method('load')->willReturnSelf();
        $role->method('getId')->willReturn(1);
        $role->method('getRoleName')->willReturn('Administrators');

        $this->userFactory->method('create')->willReturn($collectionUser);
        $this->roleFactory->method('create')->willReturn($role);

        $out = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('adminusers', $out);
        $this->assertCount(1, $out['adminusers']);
        $this->assertSame('Administrators', $out['adminusers'][0]['rolename']);
        $entry = $out['adminusers'][0]['users'][0];
        $this->assertSame('jane@example.com', $entry['email']);
        $this->assertSame('en_US', $entry['interface_locale']);
        $this->assertArrayNotHasKey('password', $entry);
    }

    public function testRefreshExportRereadsTrackedUserAndNeverWritesPassword(): void
    {
        // Loaded DB user for the tracked email.
        $dbUser = $this->userMock();
        $dbUser->method('getId')->willReturn(42);
        $dbUser->method('getUserName')->willReturn('jdoe');
        $dbUser->method('getFirstName')->willReturn('Jane');
        $dbUser->method('getLastName')->willReturn('Doe');
        $dbUser->method('getEmail')->willReturn('jane@example.com');
        $dbUser->method('getInterfaceLocale')->willReturn(null);
        $dbUser->method('getRole')->willReturn(null);
        $dbUser->method('getRoleId')->willReturn(1);

        $collection = $this->createMock(UserCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($dbUser);

        $collectionUser = $this->userMock();
        $collectionUser->method('getCollection')->willReturn($collection);
        $this->userFactory->method('create')->willReturn($collectionUser);

        $role = $this->roleMock();
        $role->method('load')->willReturnSelf();
        $role->method('getId')->willReturn(1);
        $role->method('getRoleName')->willReturn('Administrators');
        $this->roleFactory->method('create')->willReturn($role);

        $existing = ['adminusers' => [
            ['rolename' => 'Administrators', 'users' => [
                ['username' => 'jdoe', 'email' => 'jane@example.com', 'password' => 'old-secret', 'version' => 3],
            ]],
        ]];

        $out = $this->component->export(new ExportContext($existing, false));

        $entry = $out['adminusers'][0]['users'][0];
        $this->assertSame('jane@example.com', $entry['email']);
        // Refreshed full state, password never carried over, structural keys preserved.
        $this->assertArrayNotHasKey('password', $entry);
        $this->assertSame(3, $entry['version']);
    }

    public function testRefreshExportKeepsUntrackedDataUntouched(): void
    {
        // No adminusers node -> returned verbatim, no DB calls.
        $this->userFactory->expects($this->never())->method('create');

        $existing = ['something_else' => ['foo' => 'bar']];
        $out = $this->component->export(new ExportContext($existing, false));

        $this->assertSame($existing, $out);
    }

    /**
     * @param array $adminusers value of the `adminusers` node
     * @param array|null $rawData full source override (bypasses $adminusers)
     */
    private function execute(
        array $adminusers,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['adminusers' => $adminusers];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * A complete, valid user source row.
     *
     * @return array
     */
    private function userRow(): array
    {
        return [
            'username' => 'jdoe',
            'firstname' => 'Jane',
            'secondname' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 's3cret!',
        ];
    }

    private function givenRoleExists(string $name, int $roleId): void
    {
        $role = $this->roleMock();
        $role->method('getId')->willReturn($roleId);
        $role->method('getRoleName')->willReturn($name);

        $collection = $this->createMock(RoleCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($role);

        $roleModel = $this->roleMock();
        $roleModel->method('getCollection')->willReturn($collection);

        $this->roleFactory->method('create')->willReturn($roleModel);
    }

    private function givenRoleMissing(string $name): void
    {
        $role = $this->roleMock();
        $role->method('getId')->willReturn(null);

        $collection = $this->createMock(RoleCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($role);

        $roleModel = $this->roleMock();
        $roleModel->method('getCollection')->willReturn($collection);

        $this->roleFactory->method('create')->willReturn($roleModel);
    }

    /**
     * Wire the userFactory so its single create() yields a user whose collection
     * lookup reports existence (or not), and return that user for assertions. The
     * returned user is the one the component creates/saves in create mode.
     */
    private function givenNewUser(bool $exists): User&MockObject
    {
        $user = $this->userMock();
        $this->makeFluentUser($user);
        $this->givenUserLookupOnUser($user, $exists, $user);
        $this->userFactory->method('create')->willReturn($user);

        return $user;
    }

    /**
     * Configure $probe so getCollection()->addFieldToFilter()->getFirstItem()
     * returns a record whose getId() reflects existence.
     */
    private function givenUserLookup(User&MockObject $probe, bool $exists, User&MockObject $existing): void
    {
        $this->givenUserLookupOnUser($probe, $exists, $existing);
    }

    private function givenUserLookupOnUser(User&MockObject $probe, bool $exists, User&MockObject $existing): void
    {
        $existing->method('getId')->willReturn($exists ? 7 : null);

        $collection = $this->createMock(UserCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($existing);

        $probe->method('getCollection')->willReturn($collection);
    }

    /**
     * Make every fluent setter on a user return the user itself so the build
     * chain in the component does not blow up.
     */
    private function makeFluentUser(User&MockObject $user): void
    {
        $user->method('setUserName')->willReturnSelf();
        $user->method('setFirstName')->willReturnSelf();
        $user->method('setLastName')->willReturnSelf();
        $user->method('setEmail')->willReturnSelf();
        $user->method('setIsActive')->willReturnSelf();
        $user->method('setRoleId')->willReturnSelf();
        $user->method('setPassword')->willReturnSelf();
        $user->method('setInterfaceLocale')->willReturnSelf();
    }

    /**
     * Build a User mock. setRoleId/getRoleId are Magento magic (getData-backed)
     * methods not declared on the class, so they must be registered via
     * addMethods(); the rest are real declared methods on Magento\User\Model\User.
     */
    private function userMock(): User&MockObject
    {
        return $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->addMethods(['getRoleId', 'setRoleId'])
            ->onlyMethods([
                'getCollection',
                'load',
                'getId',
                'getRole',
                'getUserName',
                'getFirstName',
                'getLastName',
                'getEmail',
                'getInterfaceLocale',
                'setUserName',
                'setFirstName',
                'setLastName',
                'setEmail',
                'setIsActive',
                'setPassword',
                'setInterfaceLocale',
                'validate',
            ])
            ->getMock();
    }

    /**
     * Build a Role mock. getRoleName is a Magento magic getter not declared on
     * Magento\Authorization\Model\Role, so it must be registered via addMethods().
     */
    private function roleMock(): Role&MockObject
    {
        return $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->addMethods(['getRoleName'])
            ->onlyMethods(['getCollection', 'load', 'getId'])
            ->getMock();
    }
}
