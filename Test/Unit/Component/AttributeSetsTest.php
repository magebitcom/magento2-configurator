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
use Magebit\Configurator\Component\AttributeSets;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Set as AttributeSet;
use Magento\Eav\Model\Entity\Type;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttributeSetsTest extends TestCase
{
    private EavSetup&MockObject $eavSetup;
    private AttributeSetRepositoryInterface&MockObject $attributeSetRepository;
    private LoggerInterface&MockObject $log;
    private ResourceConnection&MockObject $resourceConnection;
    private EavConfig&MockObject $eavConfig;
    private VersionManagementInterface&MockObject $versionManagement;
    private AttributeSets $component;

    protected function setUp(): void
    {
        $this->eavSetup = $this->createMock(EavSetup::class);
        $this->attributeSetRepository = $this->createMock(AttributeSetRepositoryInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->eavConfig = $this->createMock(EavConfig::class);

        // Real gate over a version store that reports "not newer" by default.
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);

        $this->component = new AttributeSets(
            $this->eavSetup,
            $this->attributeSetRepository,
            $this->log,
            $gate,
            $this->resourceConnection,
            $this->eavConfig
        );
    }

    public function testCreatesAttributeSetWhenItDoesNotExist(): void
    {
        $this->givenAttributeSetMissing('Shirts');
        $this->eavSetup->expects($this->once())
            ->method('addAttributeSet')
            ->with(Product::ENTITY, 'Shirts');
        $this->eavSetup->method('getAttributeSetId')->willReturn(10);

        $this->attributeSetRepository->method('get')->with(10)
            ->willReturn($this->createMock(AttributeSet::class));

        $result = $this->execute([
            ['name' => 'Shirts'],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingAttributeSet(): void
    {
        // Set already exists and no version bump -> create mode must skip it.
        $this->givenAttributeSetExists('Shirts', 10);
        $this->eavSetup->expects($this->never())->method('addAttributeSet');
        $this->attributeSetRepository->expects($this->never())->method('get');

        $result = $this->execute([
            ['name' => 'Shirts'],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeReconcilesExistingAttributeSet(): void
    {
        $this->givenAttributeSetExists('Shirts', 10);
        // Maintain mode reconciles: it loads the existing set but does NOT
        // re-create it or re-init it from a skeleton.
        $this->eavSetup->expects($this->never())->method('addAttributeSet');
        $this->attributeSetRepository->expects($this->once())->method('get')->with(10)
            ->willReturn($this->createMock(AttributeSet::class));
        $this->attributeSetRepository->expects($this->never())->method('save');

        $result = $this->execute([
            ['name' => 'Shirts'],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testVersionBumpForcesUpdateInCreateMode(): void
    {
        // A bumped version makes the gate update an existing set even in create mode.
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->versionManagement->method('isNewVersion')->willReturn(true);
        $gate = new ReconciliationGate($this->versionManagement, $this->log);
        $this->component = new AttributeSets(
            $this->eavSetup,
            $this->attributeSetRepository,
            $this->log,
            $gate,
            $this->resourceConnection,
            $this->eavConfig
        );

        $this->givenAttributeSetExists('Shirts', 10);
        $this->eavSetup->expects($this->never())->method('addAttributeSet');
        $this->attributeSetRepository->expects($this->once())->method('get')->with(10)
            ->willReturn($this->createMock(AttributeSet::class));
        $this->versionManagement->expects($this->once())->method('setVersion')->with('attribute_sets_Shirts', 2);

        $result = $this->execute([
            ['name' => 'Shirts', 'version' => 2],
        ]);

        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testCreatesGroupsAndAssociatesAttributes(): void
    {
        $this->givenAttributeSetMissing('Shirts');
        $this->eavSetup->method('getAttributeSetId')->willReturn(10);

        $set = $this->createMock(AttributeSet::class);
        $set->method('getAttributeSetName')->willReturn('Shirts');
        $set->method('getId')->willReturn(10);
        $this->attributeSetRepository->method('get')->with(10)->willReturn($set);

        $this->eavSetup->method('convertToAttributeGroupCode')->willReturn('clothing-info');
        // Group does not exist yet -> it is created.
        $this->eavSetup->method('getAttributeGroup')->willReturn(false);
        $this->eavSetup->expects($this->once())
            ->method('addAttributeGroup')
            ->with(Product::ENTITY, 'Shirts', 'Clothing Info');
        // Attribute exists.
        $this->eavSetup->method('getAttribute')->willReturn(['attribute_id' => 99]);
        $this->eavSetup->expects($this->once())
            ->method('addAttributeToGroup')
            ->with(Product::ENTITY, 10, 'Clothing Info', 'size');

        $result = $this->execute([
            [
                'name' => 'Shirts',
                'groups' => [
                    ['name' => 'Clothing Info', 'attributes' => ['size']],
                ],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testErrorWhenGroupReferencesMissingAttribute(): void
    {
        $this->givenAttributeSetMissing('Shirts');
        $this->eavSetup->method('getAttributeSetId')->willReturn(10);

        $set = $this->createMock(AttributeSet::class);
        $set->method('getAttributeSetName')->willReturn('Shirts');
        $set->method('getId')->willReturn(10);
        $this->attributeSetRepository->method('get')->with(10)->willReturn($set);

        $this->eavSetup->method('convertToAttributeGroupCode')->willReturn('clothing-info');
        $this->eavSetup->method('getAttributeGroup')->willReturn(false);
        // Attribute does not exist -> ComponentException -> recorded error, no association.
        $this->eavSetup->method('getAttribute')->willReturn([]);
        $this->eavSetup->expects($this->never())->method('addAttributeToGroup');

        $result = $this->execute([
            [
                'name' => 'Shirts',
                'groups' => [
                    ['name' => 'Clothing Info', 'attributes' => ['does_not_exist']],
                ],
            ],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenAttributeSetMissing('Shirts');
        $this->eavSetup->expects($this->never())->method('addAttributeSet');
        $this->attributeSetRepository->expects($this->never())->method('get');
        $this->attributeSetRepository->expects($this->never())->method('save');

        $result = $this->execute([
            ['name' => 'Shirts'],
        ], true);

        // Intent is still recorded even though nothing is persisted.
        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->eavSetup->expects($this->never())->method('addAttributeSet');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testFullExportReturnsEverySetInScope(): void
    {
        $this->givenExportEntityType(4);
        $connection = $this->givenConnection();

        // Two sets; neither has any user-defined attributes, so no groups are emitted.
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                ['attribute_set_id' => 5, 'attribute_set_name' => 'Shirts'],
            ],
            [],
            []
        );

        $result = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('attribute_sets', $result);
        $this->assertCount(2, $result['attribute_sets']);
        $this->assertSame('Default', $result['attribute_sets'][0]['name']);
        $this->assertSame('Shirts', $result['attribute_sets'][1]['name']);
    }

    public function testRefreshExportOnlyReExportsTrackedSetsPreservingInheritAndVersion(): void
    {
        $this->givenExportEntityType(4);
        $connection = $this->givenConnection();

        // fetchSets() returns the live sets; groups fetch returns none for the tracked set.
        $connection->method('fetchAll')->willReturnCallback(
            static function () {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    return [
                        ['attribute_set_id' => 5, 'attribute_set_name' => 'Shirts'],
                        ['attribute_set_id' => 6, 'attribute_set_name' => 'Untracked'],
                    ];
                }

                return [];
            }
        );

        $existing = [
            'attribute_sets' => [
                ['name' => 'Shirts', 'inherit' => 'Default', 'version' => 3],
            ],
        ];

        $result = $this->component->export(new ExportContext($existing, false));

        // Only the tracked 'Shirts' set is re-exported; 'Untracked' is ignored.
        $this->assertCount(1, $result['attribute_sets']);
        $entry = $result['attribute_sets'][0];
        $this->assertSame('Shirts', $entry['name']);
        // Non-DB keys are preserved verbatim.
        $this->assertSame('Default', $entry['inherit']);
        $this->assertSame(3, $entry['version']);
    }

    public function testRefreshExportKeepsTrackedEntryWhenSetNoLongerExists(): void
    {
        $this->givenExportEntityType(4);
        $connection = $this->givenConnection();
        // Live sets no longer contain the tracked one.
        $connection->method('fetchAll')->willReturn([]);

        $existing = [
            'attribute_sets' => [
                ['name' => 'Gone', 'inherit' => 'Default'],
            ],
        ];

        $result = $this->component->export(new ExportContext($existing, false));

        // The tracked entry is kept untouched.
        $this->assertCount(1, $result['attribute_sets']);
        $this->assertSame(['name' => 'Gone', 'inherit' => 'Default'], $result['attribute_sets'][0]);
    }

    /**
     * @param array $attributeSets value of the `attribute_sets` node
     * @param array|null $rawData full source override (bypasses $attributeSets)
     */
    private function execute(
        array $attributeSets,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['attribute_sets' => $attributeSets];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    private function givenAttributeSetMissing(string $name): void
    {
        // getAttributeSet returns false (or no id) when the set does not exist.
        $this->eavSetup->method('getAttributeSet')
            ->with(Product::ENTITY, $name)
            ->willReturn(false);
    }

    private function givenAttributeSetExists(string $name, int $id): void
    {
        $this->eavSetup->method('getAttributeSet')
            ->with(Product::ENTITY, $name)
            ->willReturn(['attribute_set_id' => $id, 'attribute_set_name' => $name]);
    }

    private function givenExportEntityType(int $entityTypeId): void
    {
        $type = $this->createMock(Type::class);
        $type->method('getId')->willReturn($entityTypeId);
        $this->eavConfig->method('getEntityType')->with(Product::ENTITY)->willReturn($type);
    }

    private function givenConnection(): AdapterInterface&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn([]);

        $this->resourceConnection->method('getConnection')->willReturn($connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        return $connection;
    }
}
