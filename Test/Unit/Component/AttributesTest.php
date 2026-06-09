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
use Magebit\Configurator\Component\Attributes;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeSearchResultsInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Option;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as AttrOptionCollectionFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Swatches\Helper\Data as SwatchHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttributesTest extends TestCase
{
    private EavSetup&MockObject $eavSetup;
    private AttributeRepositoryInterface&MockObject $attributeRepository;
    private LoggerInterface&MockObject $log;
    private AttrOptionCollectionFactory&MockObject $attrOptionCollectionFactory;
    private EavConfig&MockObject $eavConfig;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private SwatchHelper&MockObject $swatchHelper;
    private Attributes $component;

    protected function setUp(): void
    {
        $this->eavSetup = $this->createMock(EavSetup::class);
        $this->attributeRepository = $this->createMock(AttributeRepositoryInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->attrOptionCollectionFactory = $this->getMockBuilder(AttrOptionCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->swatchHelper = $this->createMock(SwatchHelper::class);

        // Fluent builder: addFilter() chains, create() yields a criteria.
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new Attributes(
            $this->eavSetup,
            $this->attributeRepository,
            $this->log,
            $this->attrOptionCollectionFactory,
            $this->eavConfig,
            $gate,
            $this->searchCriteriaBuilder,
            $this->swatchHelper
        );
    }

    public function testCreatesAttributeWhenItDoesNotExist(): void
    {
        // No existing attribute -> getAttribute returns falsy.
        $this->eavSetup->method('getAttribute')->willReturn(false);

        $this->eavSetup->expects($this->once())
            ->method('addAttribute')
            ->with('catalog_product', 'colour', $this->callback(static function (array $config): bool {
                // user_defined defaulted on for brand-new attributes.
                return ($config['user_defined'] ?? null) === 1 && $config['label'] === 'Colour';
            }));

        $result = $this->execute([
            'colour' => ['label' => 'Colour', 'input' => 'text', 'type' => 'varchar'],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingAttribute(): void
    {
        // Attribute exists with a DIFFERENT label, but create mode must not touch it.
        $this->givenExistingAttribute(['frontend_label' => 'Old Label']);

        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([
            'colour' => ['label' => 'New Label', 'input' => 'text'],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesChangedAttribute(): void
    {
        // Existing label differs from config -> maintain mode updates it.
        $this->givenExistingAttribute(['frontend_label' => 'Old Label']);

        $this->eavSetup->expects($this->once())->method('addAttribute');

        $result = $this->execute([
            'colour' => ['label' => 'New Label', 'input' => 'text'],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedAttribute(): void
    {
        // Existing attribute already matches the config (mapped key + value) -> skip.
        $this->givenExistingAttribute(['frontend_label' => 'Colour', 'frontend_input' => 'text']);

        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([
            'colour' => ['label' => 'Colour', 'input' => 'text'],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->eavSetup->method('getAttribute')->willReturn(false);

        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([
            'colour' => ['label' => 'Colour', 'input' => 'text'],
        ], true);

        // Intent is still recorded even though nothing is written.
        $this->assertSame(1, $result->getCreated());
    }

    public function testRemoveDeletesExistingUserDefinedAttribute(): void
    {
        // Existing user-defined attribute -> removeAttribute called once, no save.
        $this->givenExistingAttribute(['is_user_defined' => 1]);

        $this->eavSetup->expects($this->once())
            ->method('removeAttribute')
            ->with('catalog_product', 'colour');
        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([
            'colour' => ['remove' => true],
        ]);

        $this->assertSame(1, $result->getRemoved());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testRemoveAbsentAttributeIsSkipped(): void
    {
        // No existing attribute -> nothing to remove, recorded as a skip.
        $this->eavSetup->method('getAttribute')->willReturn(false);

        $this->eavSetup->expects($this->never())->method('removeAttribute');
        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([
            'colour' => ['remove' => true],
        ]);

        $this->assertSame(0, $result->getRemoved());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testRemoveDryRunDoesNotDelete(): void
    {
        $this->givenExistingAttribute(['is_user_defined' => 1]);

        $this->eavSetup->expects($this->never())->method('removeAttribute');
        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([
            'colour' => ['remove' => true],
        ], true);

        // Intent is still recorded even though nothing is deleted.
        $this->assertSame(1, $result->getRemoved());
    }

    public function testRecordsErrorWhenAttributesNodeMissing(): void
    {
        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testFullExportReversesConfigMapAndExportsOptions(): void
    {
        $this->searchCriteriaBuilder->expects($this->atLeastOnce())->method('addFilter');

        $listed = $this->createMock(AttributeInterface::class);
        $listed->method('getAttributeCode')->willReturn('colour');

        $results = $this->createMock(AttributeSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$listed]);
        $this->attributeRepository->method('getList')->willReturn($results);

        // EavSetup raw row: the friendly keys are reversed from the EAV column names.
        $this->eavSetup->method('getAttribute')->willReturn([
            'attribute_id' => 5,
            'frontend_label' => 'Colour',
            'frontend_input' => 'select',
            'is_required' => 0,
            'apply_to' => 'simple,configurable',
        ]);

        // A plain (non-swatch) select with two options.
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getId')->willReturn(5);
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getOptions')->willReturn([
            $this->givenOption('1', 'Red'),
            $this->givenOption('2', 'Green'),
            // Placeholder option with an empty value must be skipped.
            $this->givenOption('', '-- Please Select --'),
        ]);
        $this->eavConfig->method('getAttribute')->willReturn($attribute);
        $this->swatchHelper->method('isSwatchAttribute')->willReturn(false);

        $export = $this->component->export(new ExportContext([], true));

        $this->assertArrayHasKey('attributes', $export);
        $this->assertArrayHasKey('colour', $export['attributes']);

        $entry = $export['attributes']['colour'];
        $this->assertSame('Colour', $entry['label']);
        $this->assertSame('select', $entry['input']);
        $this->assertSame(['simple', 'configurable'], $entry['product_types']);
        $this->assertSame(['values' => ['Red', 'Green']], $entry['option']);
    }

    public function testRefreshExportOnlyRewritesTrackedKeys(): void
    {
        // Refresh mode: only the keys present in the tracked entry are updated.
        $this->eavSetup->method('getAttribute')->willReturn([
            'attribute_id' => 5,
            'frontend_label' => 'Fresh Colour',
            'frontend_input' => 'text',
            'is_required' => 1,
        ]);

        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getId')->willReturn(5);
        $attribute->method('getFrontendInput')->willReturn('text');
        $this->eavConfig->method('getAttribute')->willReturn($attribute);
        $this->swatchHelper->method('isSwatchAttribute')->willReturn(false);

        $existing = ['attributes' => ['colour' => ['label' => 'Stale Colour']]];

        $export = $this->component->export(new ExportContext($existing, false));

        // Tracked 'label' refreshed; untracked keys (e.g. required) NOT introduced.
        $this->assertSame('Fresh Colour', $export['attributes']['colour']['label']);
        $this->assertArrayNotHasKey('required', $export['attributes']['colour']);
    }

    /**
     * @param array $attributeCode value of the `attributes` node (keyed by code)
     * @param array|null $rawData full source override (bypasses $attributeCode)
     */
    private function execute(
        array $attributeCode,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['attributes' => $attributeCode];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * @param array $columns EAV row columns (merged over a valid attribute_id).
     */
    private function givenExistingAttribute(array $columns): void
    {
        $this->eavSetup->method('getAttribute')->willReturn(array_merge(['attribute_id' => 5], $columns));
    }

    private function givenOption(string $value, string $label): Option&MockObject
    {
        $option = $this->createMock(Option::class);
        $option->method('getValue')->willReturn($value);
        $option->method('getLabel')->willReturn($label);

        return $option;
    }
}
