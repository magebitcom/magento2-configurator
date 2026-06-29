<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Model\Import\Importer;
use Magebit\Configurator\Model\Import\ImporterFactory;
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Component\Product\AttributeOption;
use Magebit\Configurator\Component\Product\Image;
use Magebit\Configurator\Component\Product\Validator;
use Magebit\Configurator\Component\Product\ValidatorFactory;
use Magebit\Configurator\Component\Products;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductsTest extends TestCase
{
    private ImporterFactory&MockObject $importerFactory;
    private ProductFactory&MockObject $productFactory;
    private Image&MockObject $image;
    private ValidatorFactory&MockObject $validatorFactory;
    private AttributeOption&MockObject $attributeOption;
    private LoggerInterface&MockObject $log;
    private ProductCollectionFactory&MockObject $productCollectionFactory;
    private SourceItemInterfaceFactory&MockObject $sourceItemFactory;
    private SourceItemsSaveInterface&MockObject $sourceItemsSave;
    private Products $component;

    protected function setUp(): void
    {
        $this->importerFactory = $this->getMockBuilder(ImporterFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->productFactory = $this->getMockBuilder(ProductFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->image = $this->createMock(Image::class);
        $this->validatorFactory = $this->getMockBuilder(ValidatorFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->attributeOption = $this->createMock(AttributeOption::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->productCollectionFactory = $this->getMockBuilder(ProductCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->sourceItemFactory = $this->getMockBuilder(SourceItemInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->sourceItemsSave = $this->createMock(SourceItemsSaveInterface::class);

        // Image is a passthrough in these tests: a configured image value comes
        // back unchanged so prepared rows are predictable.
        $this->image->method('getImage')->willReturnArgument(0);

        $this->component = new Products(
            $this->importerFactory,
            $this->productFactory,
            $this->image,
            $this->validatorFactory,
            $this->attributeOption,
            $this->log,
            $this->productCollectionFactory,
            $this->sourceItemFactory,
            $this->sourceItemsSave
        );
    }

    public function testRecordsErrorWhenHeaderRowMissing(): void
    {
        // No row index 0 -> the component cannot read the CSV header and bails.
        $this->importerFactory->expects($this->never())->method('create');
        $this->validatorFactory->expects($this->never())->method('create');

        $result = $this->execute(['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testImportsSimpleProductInMaintainMode(): void
    {
        // Maintain mode always re-imports (no SKU pre-diff), so a single valid
        // row reaches the importer's processImport().
        $this->givenNoExistingSkus();
        $importer = $this->givenImporterPassesThrough();
        $importer->expects($this->once())->method('processImport');

        $result = $this->execute(
            $this->csv(
                ['sku', 'name'],
                [['widget-1', 'Widget One']]
            ),
            false,
            ComponentMode::Maintain
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testSkipsRowWithWrongColumnCount(): void
    {
        // A row whose cell count differs from the header is gated out by SKU
        // before import. The remaining valid row still imports.
        $this->givenNoExistingSkus();
        $importer = $this->givenImporterPassesThrough();
        $importer->expects($this->once())->method('processImport')->with(
            $this->callback(static fn (array $rows): bool => count($rows) === 1
                && ($rows[0]['sku'] ?? null) === 'good')
        );

        $result = $this->execute(
            $this->csv(
                ['sku', 'name'],
                [
                    ['malformed'],            // only one cell -> skipped
                    ['good', 'Good Product'],
                ]
            ),
            false,
            ComponentMode::Maintain
        );

        $this->assertTrue($result->isSuccessful());
    }

    public function testEmptySetGuardStopsBeforeImport(): void
    {
        // Every data row is malformed, so nothing survives preparation. The
        // mandatory empty-set guard must stop before touching the importer.
        $this->importerFactory->expects($this->never())->method('create');
        $this->validatorFactory->expects($this->never())->method('create');

        $result = $this->execute(
            $this->csv(
                ['sku', 'name'],
                [
                    ['only-one-cell'],
                    ['also-one'],
                ]
            ),
            false,
            ComponentMode::Maintain
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getSkipped());
    }

    public function testCreateModeSkipsExistingSku(): void
    {
        // Create mode (no version) drops rows whose SKU already exists. Here the
        // only row exists, so nothing is left and the empty-set guard returns.
        $this->givenExistingSkus(['widget-1']);
        $this->importerFactory->expects($this->never())->method('create');
        $this->validatorFactory->expects($this->never())->method('create');

        $result = $this->execute(
            $this->csv(
                ['sku', 'name'],
                [['widget-1', 'Widget One']]
            )
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testCreateModeImportsOnlyNewSkus(): void
    {
        // One existing SKU is dropped, the new one imports.
        $this->givenExistingSkus(['old']);
        $importer = $this->givenImporterPassesThrough();
        $importer->expects($this->once())->method('processImport')->with(
            $this->callback(static fn (array $rows): bool => count($rows) === 1
                && ($rows[0]['sku'] ?? null) === 'new')
        );

        $result = $this->execute(
            $this->csv(
                ['sku', 'name'],
                [
                    ['old', 'Old Product'],
                    ['new', 'New Product'],
                ]
            )
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotProcessImport(): void
    {
        // Dry-run validates but must never persist via processImport().
        $this->givenNoExistingSkus();
        $importer = $this->givenImporterPassesThrough();
        $importer->expects($this->never())->method('processImport');

        $result = $this->execute(
            $this->csv(
                ['sku', 'name'],
                [['widget-1', 'Widget One']]
            ),
            true,
            ComponentMode::Maintain
        );

        $this->assertTrue($result->isSuccessful());
    }

    public function testVersionBumpForcesImportOfExistingSkuInCreateMode(): void
    {
        // A component-level version forces a full re-import even in create mode,
        // so existing SKUs are NOT pre-diffed away.
        $this->productCollectionFactory->expects($this->never())->method('create');
        $importer = $this->givenImporterPassesThrough();
        $importer->expects($this->once())->method('processImport');

        $result = $this->execute(
            $this->csv(
                ['sku', 'name'],
                [['widget-1', 'Widget One']]
            ),
            false,
            ComponentMode::Create,
            5
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getSkipped());
    }

    /**
     * Build the CSV-style data array the component expects: index 0 is the
     * header row, subsequent indexes are data rows.
     *
     * @param string[] $header
     * @param array<int, string[]> $rows
     * @return array<int, string[]>
     */
    private function csv(array $header, array $rows): array
    {
        return array_merge([$header], $rows);
    }

    /**
     * @param array<int, string[]>|array<string, mixed> $data raw source data
     */
    private function execute(
        array $data,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create,
        ?int $version = null
    ): ComponentResult {
        $context = new ComponentContext(
            'test.csv',
            $mode,
            'test',
            $dryRun,
            static fn (): array => $data,
            $version
        );

        return $this->component->execute($context);
    }

    /**
     * Wire the importer + validator so the prepared rows pass straight through
     * to processImport() unchanged. Returns the Importer mock for assertions.
     */
    private function givenImporterPassesThrough(): Importer&MockObject
    {
        $importer = $this->createMock(Importer::class);
        $importer->method('setMultipleValueSeparator')->willReturnSelf();
        $importer->method('getLogTrace')->willReturn('');
        $importer->method('getErrorMessages')->willReturn('');
        $this->importerFactory->method('create')->willReturn($importer);

        $validator = $this->createMock(Validator::class);
        $validator->method('getValidatedImport')->willReturnCallback(
            static fn (Importer $i, array $lines): array => $lines
        );
        $validator->method('getRemovedRows')->willReturn([]);
        $this->validatorFactory->method('create')->willReturn($validator);

        return $importer;
    }

    private function givenNoExistingSkus(): void
    {
        $this->givenExistingSkus([]);
    }

    /**
     * @param string[] $skus
     */
    private function givenExistingSkus(array $skus): void
    {
        $products = [];
        foreach ($skus as $sku) {
            $product = new class ($sku) {
                public function __construct(private readonly string $sku)
                {
                }

                public function getSku(): string
                {
                    return $this->sku;
                }
            };
            $products[] = $product;
        }

        $collection = $this->getMockBuilder(ProductCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addAttributeToSelect', 'addFieldToFilter', 'getIterator'])
            ->getMock();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        $this->productCollectionFactory->method('create')->willReturn($collection);
    }
}
