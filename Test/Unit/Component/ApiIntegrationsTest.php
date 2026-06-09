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
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Component\ApiIntegrations;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Model\AuthorizationService;
use Magento\Integration\Model\Integration as IntegrationModel;
use Magento\Integration\Model\IntegrationFactory;
use Magento\Integration\Model\Oauth\Token;
use Magento\Integration\Model\Oauth\TokenFactory;
use Magento\Integration\Model\ResourceModel\Integration\Collection as IntegrationCollection;
use Magento\Integration\Model\ResourceModel\Oauth\Token as TokenResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiIntegrationsTest extends TestCase
{
    private IntegrationFactory&MockObject $integrationFactory;
    private IntegrationServiceInterface&MockObject $integrationService;
    private AuthorizationService&MockObject $authorizationService;
    private TokenFactory&MockObject $tokenFactory;
    private TokenResource&MockObject $tokenResource;
    private LoggerInterface&MockObject $log;
    private ApiIntegrations $component;

    protected function setUp(): void
    {
        $this->integrationFactory = $this->getMockBuilder(IntegrationFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->integrationService = $this->createMock(IntegrationServiceInterface::class);
        $this->authorizationService = $this->createMock(AuthorizationService::class);
        $this->tokenFactory = $this->getMockBuilder(TokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->tokenResource = $this->createMock(TokenResource::class);
        $this->log = $this->createMock(LoggerInterface::class);

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new ApiIntegrations(
            $this->integrationFactory,
            $this->integrationService,
            $this->authorizationService,
            $this->tokenFactory,
            $this->tokenResource,
            $this->log,
            $gate
        );
    }

    public function testCreatesIntegrationWhenItDoesNotExist(): void
    {
        $this->givenLookupReturnsId(null);

        // Created integration mints a token (creation only).
        // getConsumerId is a magic data getter, not a declared method -> addMethods().
        $created = $this->getMockBuilder(IntegrationModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getConsumerId'])
            ->getMock();
        $created->method('getId')->willReturn(42);
        $created->method('getConsumerId')->willReturn(7);
        $this->integrationService->expects($this->once())
            ->method('create')
            ->willReturn($created);
        $this->integrationService->expects($this->never())->method('update');

        $this->authorizationService->expects($this->once())
            ->method('grantPermissions')
            ->with(42, ['Magento_Backend::admin']);

        // setType is a magic data setter -> addMethods(); createVerifierToken is declared.
        $token = $this->getMockBuilder(Token::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createVerifierToken'])
            ->addMethods(['setType'])
            ->getMock();
        $token->expects($this->once())->method('createVerifierToken')->with(7);
        $token->expects($this->once())->method('setType')->with('access');
        $this->tokenFactory->method('create')->willReturn($token);
        $this->tokenResource->expects($this->once())->method('save')->with($token);

        $result = $this->execute([$this->givenRow()]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingIntegration(): void
    {
        $this->givenLookupReturnsId(99);

        // Create mode never touches an existing integration (no version bump).
        $this->integrationService->expects($this->never())->method('create');
        $this->integrationService->expects($this->never())->method('update');
        $this->tokenResource->expects($this->never())->method('save');

        $result = $this->execute([$this->givenRow()]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingIntegrationWithoutRemintingToken(): void
    {
        $this->givenLookupReturnsId(99);

        $updated = $this->createMock(IntegrationModel::class);
        $updated->method('getId')->willReturn(99);

        // Maintain updates metadata + re-grants permissions, but NEVER creates a
        // new integration nor mints a fresh token.
        $this->integrationService->expects($this->never())->method('create');
        $this->integrationService->expects($this->once())
            ->method('update')
            ->willReturn($updated);
        $this->authorizationService->expects($this->once())
            ->method('grantPermissions')
            ->with(99, ['Magento_Backend::admin']);
        $this->tokenFactory->expects($this->never())->method('create');
        $this->tokenResource->expects($this->never())->method('save');

        $result = $this->execute([$this->givenRow()], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenLookupReturnsId(null);

        // Dry-run records intent but performs no writes at all.
        $this->integrationService->expects($this->never())->method('create');
        $this->integrationService->expects($this->never())->method('update');
        $this->tokenResource->expects($this->never())->method('save');

        $result = $this->execute([$this->givenRow()], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->integrationFactory->expects($this->never())->method('create');
        $this->integrationService->expects($this->never())->method('create');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRowWithoutNameIsSkippedWithoutError(): void
    {
        // No name -> logged and skipped; nothing is created and the run still succeeds.
        $this->integrationFactory->expects($this->never())->method('create');
        $this->integrationService->expects($this->never())->method('create');
        $this->log->expects($this->atLeastOnce())->method('logError');

        $result = $this->execute([['email' => 'x@example.com']]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemoveDeletesExistingIntegration(): void
    {
        $this->givenLookupReturnsId(99);

        // Existing integration is deleted via the service; never saved/created/updated.
        $this->integrationService->expects($this->once())->method('delete')->with(99);
        $this->integrationService->expects($this->never())->method('create');
        $this->integrationService->expects($this->never())->method('update');
        $this->tokenResource->expects($this->never())->method('save');

        $row = $this->givenRow();
        $row['remove'] = true;

        $result = $this->execute([$row]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRemoveAbsentIntegrationIsSkipped(): void
    {
        $this->givenLookupReturnsId(null);

        // Nothing to delete -> skipped, no delete call, no error.
        $this->integrationService->expects($this->never())->method('delete');
        $this->integrationService->expects($this->never())->method('create');
        $this->integrationService->expects($this->never())->method('update');

        $row = $this->givenRow();
        $row['remove'] = true;

        $result = $this->execute([$row]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testRemoveDryRunDeletesNothing(): void
    {
        $this->givenLookupReturnsId(99);

        // Dry-run records intent but performs no delete.
        $this->integrationService->expects($this->never())->method('delete');
        $this->integrationService->expects($this->never())->method('create');
        $this->integrationService->expects($this->never())->method('update');

        $row = $this->givenRow();
        $row['remove'] = true;

        $result = $this->execute([$row], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
    }

    public function testFullExportDumpsEveryIntegrationWithoutSecrets(): void
    {
        $integration = $this->givenIntegrationModel(
            'Test Integration',
            'integration@example.com',
            'https://callback.test',
            'https://identity.test'
        );

        $collection = $this->createMock(IntegrationCollection::class);
        $collection->method('getIterator')->willReturn(new ArrayIterator([$integration]));
        $collection->expects($this->never())->method('addFieldToFilter');

        $factoryModel = $this->createMock(IntegrationModel::class);
        $factoryModel->method('getCollection')->willReturn($collection);
        $this->integrationFactory->method('create')->willReturn($factoryModel);

        $this->integrationService->method('getSelectedResources')
            ->with(5)
            ->willReturn(['Magento_Backend::admin']);

        $out = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('apiintegrations', $out);
        $this->assertCount(1, $out['apiintegrations']);

        $entry = $out['apiintegrations'][0];
        $this->assertSame('Test Integration', $entry['name']);
        $this->assertSame('integration@example.com', $entry['email']);
        $this->assertSame('https://callback.test', $entry['callbackurl']);
        $this->assertSame('https://identity.test', $entry['identityurl']);
        $this->assertSame(['Magento_Backend::admin'], $entry['resources']);

        // Tokens / consumer keys / secrets must NEVER leak into the export.
        $this->assertExportHasNoSecrets($entry);
    }

    public function testRefreshExportRewritesOnlyTrackedEntries(): void
    {
        $integration = $this->givenIntegrationModel(
            'Tracked',
            'fresh@example.com',
            'https://new-callback.test',
            'https://new-identity.test'
        );

        $byNameCollection = $this->createMock(IntegrationCollection::class);
        $byNameCollection->method('addFieldToFilter')->with('name', 'Tracked')->willReturnSelf();
        $byNameCollection->method('getFirstItem')->willReturn($integration);

        $factoryModel = $this->createMock(IntegrationModel::class);
        $factoryModel->method('getCollection')->willReturn($byNameCollection);
        $this->integrationFactory->method('create')->willReturn($factoryModel);

        $this->integrationService->method('getSelectedResources')
            ->with(5)
            ->willReturn(['Magento_Backend::admin']);

        $existing = [
            'apiintegrations' => [
                ['name' => 'Tracked', 'version' => 3, 'callbackurl' => 'https://old.test'],
            ],
        ];

        $out = $this->component->export(new ExportContext($existing, false));

        $this->assertCount(1, $out['apiintegrations']);
        $entry = $out['apiintegrations'][0];
        // Preserved bespoke key (version) plus refreshed metadata from the DB.
        $this->assertSame(3, $entry['version']);
        $this->assertSame('fresh@example.com', $entry['email']);
        $this->assertSame('https://new-callback.test', $entry['callbackurl']);
        $this->assertExportHasNoSecrets($entry);
    }

    public function testRefreshKeepsUntrackedEntryWhenIntegrationGone(): void
    {
        // Lookup yields a model with no id -> integration no longer exists.
        $missing = $this->createMock(IntegrationModel::class);
        $missing->method('getId')->willReturn(null);

        $byNameCollection = $this->createMock(IntegrationCollection::class);
        $byNameCollection->method('addFieldToFilter')->willReturnSelf();
        $byNameCollection->method('getFirstItem')->willReturn($missing);

        $factoryModel = $this->createMock(IntegrationModel::class);
        $factoryModel->method('getCollection')->willReturn($byNameCollection);
        $this->integrationFactory->method('create')->willReturn($factoryModel);

        $this->integrationService->expects($this->never())->method('getSelectedResources');

        $existing = [
            'apiintegrations' => [
                ['name' => 'Gone', 'callbackurl' => 'https://kept.test'],
            ],
        ];

        $out = $this->component->export(new ExportContext($existing, false));

        // Entry preserved verbatim because the integration could not be found.
        $this->assertSame($existing['apiintegrations'][0], $out['apiintegrations'][0]);
    }

    /**
     * @param array $integrations value of the `apiintegrations` node
     * @param array|null $rawData full source override (bypasses $integrations)
     */
    private function execute(
        array $integrations,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['apiintegrations' => $integrations];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * A complete, valid source row for a single integration.
     */
    private function givenRow(): array
    {
        return [
            'name' => 'Test Integration',
            'email' => 'integration@example.com',
            'callbackurl' => 'https://callback.test',
            'identityurl' => 'https://identity.test',
            'resources' => ['Magento_Backend::admin'],
        ];
    }

    /**
     * Stub the name-lookup collection used by execute(): the first item carries
     * the given id (null id == not found).
     */
    private function givenLookupReturnsId(?int $id): void
    {
        $existing = $this->createMock(IntegrationModel::class);
        $existing->method('getId')->willReturn($id);

        $collection = $this->createMock(IntegrationCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($existing);

        $factoryModel = $this->createMock(IntegrationModel::class);
        $factoryModel->method('getCollection')->willReturn($collection);
        $this->integrationFactory->method('create')->willReturn($factoryModel);
    }

    private function givenIntegrationModel(
        string $name,
        string $email,
        string $endpoint,
        string $identityUrl
    ): IntegrationModel&MockObject {
        // getName/getEmail/getEndpoint/getIdentityLinkUrl are magic data getters,
        // not declared methods -> addMethods(); getId is declared on AbstractModel.
        $integration = $this->getMockBuilder(IntegrationModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getName', 'getEmail', 'getEndpoint', 'getIdentityLinkUrl'])
            ->getMock();
        $integration->method('getId')->willReturn(5);
        $integration->method('getName')->willReturn($name);
        $integration->method('getEmail')->willReturn($email);
        $integration->method('getEndpoint')->willReturn($endpoint);
        $integration->method('getIdentityLinkUrl')->willReturn($identityUrl);

        return $integration;
    }

    /**
     * Guard: no exported entry may contain a token, consumer key or secret.
     *
     * @param array<string, mixed> $entry
     */
    private function assertExportHasNoSecrets(array $entry): void
    {
        foreach (['token', 'token_secret', 'consumer_key', 'consumer_secret', 'secret', 'key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $entry);
        }
    }
}
