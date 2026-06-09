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
use Magebit\Configurator\Component\Config;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Config\Model\ResourceModel\Config\Data\Collection as ConfigDataCollection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;
use Magento\Framework\App\Config as ScopeConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\WebsiteFactory;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ConfigResource&MockObject $configResource;
    private ScopeConfig&MockObject $scopeConfig;
    private ScopeConfig\Initial&MockObject $initialConfig;
    private ThemeCollectionFactory&MockObject $themeCollectionFactory;
    private EncryptorInterface&MockObject $encryptor;
    private WebsiteFactory&MockObject $websiteFactory;
    private StoreFactory&MockObject $storeFactory;
    private LoggerInterface&MockObject $log;
    private ConfigCollectionFactory&MockObject $configValueFactory;
    private Config $component;

    protected function setUp(): void
    {
        $this->configResource = $this->createMock(ConfigResource::class);
        $this->scopeConfig = $this->createMock(ScopeConfig::class);
        $this->initialConfig = $this->createMock(ScopeConfig\Initial::class);
        $this->themeCollectionFactory = $this->getMockBuilder(ThemeCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->websiteFactory = $this->getMockBuilder(WebsiteFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->storeFactory = $this->getMockBuilder(StoreFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->log = $this->createMock(LoggerInterface::class);
        $this->configValueFactory = $this->getMockBuilder(ConfigCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        // No path is encrypted unless a test says so.
        $this->initialConfig->method('getMetadata')->willReturn([]);

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new Config(
            $this->configResource,
            $this->scopeConfig,
            $this->initialConfig,
            $this->themeCollectionFactory,
            $this->encryptor,
            $this->websiteFactory,
            $this->storeFactory,
            $this->log,
            $gate,
            $this->configValueFactory
        );
    }

    public function testCreatesGlobalConfigWhenItDoesNotExist(): void
    {
        // No existing row -> create.
        $this->givenLookupCollection(false);

        $this->configResource->expects($this->once())
            ->method('saveConfig')
            ->with('general/store/name', 'Acme', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'value' => 'Acme'],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingDifferingValue(): void
    {
        // Existing row with a DIFFERENT value, no version bump -> create mode skips.
        $this->givenLookupCollection('Old Name');

        $this->configResource->expects($this->never())->method('saveConfig');

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'value' => 'New Name'],
            ],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingDifferingValue(): void
    {
        // Existing differing row, maintain mode -> update (component records it as "created").
        $this->givenLookupCollection('Old Name');

        $this->configResource->expects($this->once())
            ->method('saveConfig')
            ->with('general/store/name', 'New Name', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'value' => 'New Name'],
            ],
        ], false, ComponentMode::Maintain);

        $this->assertSame(1, $result->getCreated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testSkipsUnchangedExistingValueInMaintainMode(): void
    {
        // Existing row already equal to the configured value -> skip in either mode.
        $this->givenLookupCollection('Acme');

        $this->configResource->expects($this->never())->method('saveConfig');

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'value' => 'Acme'],
            ],
        ], false, ComponentMode::Maintain);

        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenLookupCollection(false);

        $this->configResource->expects($this->never())->method('saveConfig');

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'value' => 'Acme'],
            ],
        ], true);

        // Intent is still recorded even though nothing is written.
        $this->assertSame(1, $result->getCreated());
    }

    public function testEncryptsValueFlaggedInSource(): void
    {
        $this->givenLookupCollection(false);

        $this->encryptor->expects($this->once())
            ->method('encrypt')
            ->with('s3cr3t')
            ->willReturn('==ENCRYPTED==');

        $this->configResource->expects($this->once())
            ->method('saveConfig')
            ->with('payment/test/key', '==ENCRYPTED==', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

        $result = $this->execute([
            'global' => [
                ['path' => 'payment/test/key', 'value' => 's3cr3t', 'encryption' => 1],
            ],
        ]);

        $this->assertSame(1, $result->getCreated());
    }

    public function testRemovesExistingGlobalConfigValue(): void
    {
        // An existing stored value -> delete it via the resource, no save.
        $this->givenLookupCollection('Acme');

        $this->configResource->expects($this->never())->method('saveConfig');
        $this->configResource->expects($this->once())
            ->method('deleteConfig')
            ->with('general/store/name', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'remove' => true],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testRemoveAbsentGlobalConfigValueIsSkipped(): void
    {
        // No stored value for the path -> idempotent skip, nothing deleted.
        $this->givenLookupCollection(false);

        $this->configResource->expects($this->never())->method('saveConfig');
        $this->configResource->expects($this->never())->method('deleteConfig');

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'remove' => true],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunRemoveDoesNotDeleteButRecordsRemoval(): void
    {
        // Existing value, dry-run -> nothing deleted but the removal intent is recorded.
        $this->givenLookupCollection('Acme');

        $this->configResource->expects($this->never())->method('saveConfig');
        $this->configResource->expects($this->never())->method('deleteConfig');

        $result = $this->execute([
            'global' => [
                ['path' => 'general/store/name', 'remove' => true],
            ],
        ], true);

        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testRecordsErrorForUnknownScope(): void
    {
        $this->configResource->expects($this->never())->method('saveConfig');

        $result = $this->execute([
            'nonsense' => [
                ['path' => 'general/store/name', 'value' => 'Acme'],
            ],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenDataEmpty(): void
    {
        $this->configResource->expects($this->never())->method('saveConfig');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testFullExportDumpsEveryRowGroupedByScope(): void
    {
        $rows = [
            $this->givenConfigRow('general/store/name', 'Acme', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0),
            $this->givenConfigRow('web/secure/base_url', 'https://example.com/', 'stores', 5),
        ];

        // exportAll iterates the collection (no path filter applied here).
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(5);
        $store->method('getCode')->willReturn('en');
        $store->method('load')->with(5)->willReturnSelf();
        $this->storeFactory->method('create')->willReturn($store);

        $this->configValueFactory->method('create')->willReturn($this->givenIterableCollection($rows));

        $result = $this->component->export(new ExportContext([], true, null, false));

        $this->assertSame(
            [['path' => 'general/store/name', 'value' => 'Acme']],
            $result['global']
        );
        $this->assertSame(
            [['path' => 'web/secure/base_url', 'value' => 'https://example.com/']],
            $result['stores']['en']
        );
    }

    public function testFullExportNeverWritesEncryptedPaths(): void
    {
        // The encrypted backend model is registered for this path in initial config.
        $this->initialConfig = $this->createMock(ScopeConfig\Initial::class);
        $this->initialConfig->method('getMetadata')->willReturn([
            'payment/test/key' => ['backendModel' => Config::ENCRYPTED_MODEL],
        ]);
        $this->rebuildComponent();

        $rows = [
            $this->givenConfigRow('payment/test/key', '==ENCRYPTED==', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0),
            $this->givenConfigRow('general/store/name', 'Acme', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0),
        ];
        $this->configValueFactory->method('create')->willReturn($this->givenIterableCollection($rows));

        $result = $this->component->export(new ExportContext([], true, null, false));

        // The plaintext store name is exported; the encrypted secret is dropped entirely.
        $this->assertSame([['path' => 'general/store/name', 'value' => 'Acme']], $result['global']);
    }

    public function testRefreshOnlyRewritesTrackedEntryValues(): void
    {
        // Refresh mode looks up the current value for each tracked path via getSetConfigValue.
        $this->givenLookupCollection('Refreshed Name');

        $existing = [
            'global' => [
                ['path' => 'general/store/name', 'value' => 'Stale Name', 'version' => 3],
            ],
        ];

        $result = $this->component->export(new ExportContext($existing, false, null, false));

        // Value is refreshed from DB; sibling keys (version) are preserved.
        $this->assertSame(
            [['path' => 'general/store/name', 'value' => 'Refreshed Name', 'version' => 3]],
            $result['global']
        );
    }

    /**
     * @param array $config full source override; keyed by scope (global/websites/stores)
     */
    private function execute(
        array $config,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $config);

        return $this->component->execute($context);
    }

    /**
     * Stub the config-value lookup collection used by getSetConfigValue(): a
     * chainable addFieldToFilter() and a getFirstItem() whose value is $value.
     * Pass false to model "no existing row".
     *
     * @param string|false $value
     */
    private function givenLookupCollection(string|false $value): void
    {
        // The real model is an AbstractModel whose getId() reads config_id; a plain
        // DataObject's getId() reads 'id', so set 'id' to make the row look existing.
        $item = new DataObject();
        if ($value !== false) {
            $item->setData('id', 1);
            $item->setData('value', $value);
        }

        $collection = $this->getMockBuilder(ConfigDataCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($item);

        $this->configValueFactory->method('create')->willReturn($collection);
    }

    /**
     * A single core_config_data row as a DataObject (getPath/getValue/getScope/getScopeId).
     */
    private function givenConfigRow(string $path, string $value, string $scope, int $scopeId): DataObject
    {
        return new DataObject([
            'path' => $path,
            'value' => $value,
            'scope' => $scope,
            'scope_id' => $scopeId,
        ]);
    }

    /**
     * An iterable collection mock for exportAll(): addFieldToFilter() chains and
     * iteration yields the supplied rows.
     *
     * @param DataObject[] $rows
     */
    private function givenIterableCollection(array $rows): ConfigDataCollection&MockObject
    {
        $collection = $this->getMockBuilder(ConfigDataCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getIterator'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        return $collection;
    }

    /**
     * Rebuild the component after swapping a collaborator mock (e.g. initialConfig).
     */
    private function rebuildComponent(): void
    {
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new Config(
            $this->configResource,
            $this->scopeConfig,
            $this->initialConfig,
            $this->themeCollectionFactory,
            $this->encryptor,
            $this->websiteFactory,
            $this->storeFactory,
            $this->log,
            $gate,
            $this->configValueFactory
        );
    }
}
