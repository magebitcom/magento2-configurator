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
use Magebit\Configurator\Component\TaxRates;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\Collection;
use Magento\TaxImportExport\Model\Rate\CsvImportHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaxRatesTest extends TestCase
{
    private CsvImportHandler&MockObject $csvImportHandler;
    private LoggerInterface&MockObject $log;
    private RateFactory&MockObject $rateFactory;
    private TaxRates $component;

    protected function setUp(): void
    {
        $this->csvImportHandler = $this->createMock(CsvImportHandler::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->rateFactory = $this->getMockBuilder(RateFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->component = new TaxRates(
            $this->csvImportHandler,
            $this->log,
            $this->rateFactory
        );
    }

    public function testImportsNewRatesInCreateMode(): void
    {
        // No existing rate codes -> nothing dropped -> import runs.
        $this->givenExistingRateCodes([]);

        $this->csvImportHandler->expects($this->once())->method('importFromCsvFile');

        $result = $this->execute($this->sampleSource());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testCreateModeSkipsExistingRateByCode(): void
    {
        // The single configured rate already exists -> dropped -> nothing left to import.
        $this->givenExistingRateCodes(['us-ca-rate']);

        $this->csvImportHandler->expects($this->never())->method('importFromCsvFile');

        $result = $this->execute($this->sampleSource());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeReimportsWithoutDroppingExisting(): void
    {
        // Maintain mode never consults the rate collection: it always re-imports.
        $this->rateFactory->expects($this->never())->method('create');
        $this->csvImportHandler->expects($this->once())->method('importFromCsvFile');

        $result = $this->execute($this->sampleSource(), false, ComponentMode::Maintain);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testVersionBumpReimportsWithoutDroppingExisting(): void
    {
        // A declared version short-circuits the create-mode drop; import always runs.
        $this->rateFactory->expects($this->never())->method('create');
        $this->csvImportHandler->expects($this->once())->method('importFromCsvFile');

        $result = $this->execute($this->sampleSource(), false, ComponentMode::Create, 5);

        $this->assertTrue($result->isSuccessful());
    }

    public function testImportFileUsesCanonicalMagentoHeader(): void
    {
        // Capture the CSV handed to the importer before the component unlinks it.
        $captured = [];
        $this->csvImportHandler->expects($this->once())
            ->method('importFromCsvFile')
            ->willReturnCallback(static function (array $file) use (&$captured): void {
                $handle = fopen($file['tmp_name'], 'r');
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $captured[] = $row;
                }
                fclose($handle);
            });

        $this->execute($this->sampleSource(), false, ComponentMode::Maintain);

        // Row 0 is Magento's canonical header, not the source machine-key header.
        $this->assertSame(
            ['Code', 'Country', 'State', 'Zip/Post Code', 'Rate', 'Zip/Post is Range', 'Range From', 'Range To'],
            $captured[0]
        );
        // The data row lines up positionally with that header: postcode at index 3,
        // rate at index 4 (the order getSortedData emits).
        $this->assertSame('US-CA-Rate', $captured[1][0]);
        $this->assertSame('US', $captured[1][1]);
        $this->assertSame('*', $captured[1][3]);
        $this->assertSame('8.2500', $captured[1][4]);
    }

    public function testDryRunDoesNotImport(): void
    {
        $this->givenExistingRateCodes([]);

        $this->csvImportHandler->expects($this->never())->method('importFromCsvFile');

        $result = $this->execute($this->sampleSource(), true);

        $this->assertTrue($result->isSuccessful());
    }

    public function testRecordsErrorWhenNoRowData(): void
    {
        $this->rateFactory->expects($this->never())->method('create');
        $this->csvImportHandler->expects($this->never())->method('importFromCsvFile');

        // Empty data array -> $data[0] not set -> error, no import.
        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testExportFullDumpsEveryRate(): void
    {
        $rate = $this->givenRate([
            'code' => 'US-CA-Rate',
            'tax_country_id' => 'US',
            'region_code' => 'CA',
            'tax_region_id' => 12,
            'rate' => 8.25,
            'tax_postcode' => '',
            'zip_is_range' => 0,
        ]);
        $this->givenCollection([$rate]);

        $out = $this->component->export(new ExportContext([], true));

        // Header row first, then one data row per rate.
        $this->assertCount(2, $out);
        $this->assertSame(
            ['code', 'tax_country_id', 'tax_region_id', 'rate', 'tax_postcode', 'zip_is_range', 'zip_from', 'zip_to'],
            $out[0]
        );
        $row = array_combine($out[0], $out[1]);
        $this->assertSame('US-CA-Rate', $row['code']);
        $this->assertSame('US', $row['tax_country_id']);
        // region_code preferred over the numeric region id.
        $this->assertSame('CA', $row['tax_region_id']);
        $this->assertSame('8.2500', $row['rate']);
        // Empty postcode normalised to '*'.
        $this->assertSame('*', $row['tax_postcode']);
        $this->assertSame('', $row['zip_is_range']);
    }

    public function testExportFullAppliesCodeFilter(): void
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getIterator'])
            ->getMock();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        // The filter prefix is pushed into the collection as a LIKE clause.
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('code', ['like' => 'US-%'])
            ->willReturnSelf();
        $this->rateFactory->method('create')->willReturn($this->wrapCollection($collection));

        $out = $this->component->export(new ExportContext([], true, 'US-'));

        // Only the header row survives an empty filtered collection.
        $this->assertCount(1, $out);
    }

    public function testExportRefreshRebuildsOnlyTrackedRates(): void
    {
        $existing = [
            ['code', 'tax_country_id', 'tax_region_id', 'rate', 'tax_postcode', 'zip_is_range', 'zip_from', 'zip_to'],
            ['US-CA-Rate', 'US', 'CA', '7.0000', '*', '', '', ''],
            ['Old-Rate', 'US', 'NY', '4.0000', '*', '', '', ''],
        ];

        // Only US-CA-Rate still exists in the DB, with an updated rate.
        $rate = $this->givenRate([
            'code' => 'US-CA-Rate',
            'tax_country_id' => 'US',
            'region_code' => 'CA',
            'tax_region_id' => 12,
            'rate' => 8.25,
            'tax_postcode' => '',
            'zip_is_range' => 0,
        ]);
        $this->givenCollection([$rate]);

        $out = $this->component->export(new ExportContext($existing, false));

        $this->assertSame($existing[0], $out[0]);
        // Tracked + present -> rebuilt from current DB values.
        $tracked = array_combine($out[0], $out[1]);
        $this->assertSame('US-CA-Rate', $tracked['code']);
        $this->assertSame('8.2500', $tracked['rate']);
        // Tracked but no longer in the DB -> existing row preserved untouched.
        $this->assertSame(['Old-Rate', 'US', 'NY', '4.0000', '*', '', '', ''], $out[2]);
    }

    public function testExportRefreshReturnsExistingWhenNoHeader(): void
    {
        $this->rateFactory->expects($this->never())->method('create');

        // No header row -> nothing to refresh, return the input verbatim.
        $out = $this->component->export(new ExportContext(['not-a-row'], false));

        $this->assertSame(['not-a-row'], $out);
    }

    /**
     * @param array $rows full source override (list of rows, index 0 is the header)
     */
    private function execute(
        array $rows,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create,
        ?int $version = null
    ): ComponentResult {
        $context = new ComponentContext(
            'test.yaml',
            $mode,
            'test',
            $dryRun,
            static fn (): array => $rows,
            $version
        );

        return $this->component->execute($context);
    }

    /**
     * A minimal two-row source (header + one rate) using the rate code `US-CA-Rate`.
     *
     * @return array
     */
    private function sampleSource(): array
    {
        return [
            ['code', 'tax_country_id', 'tax_region_id', 'tax_postcode', 'rate', 'zip_is_range', 'zip_from', 'zip_to'],
            ['US-CA-Rate', 'US', '12', '*', '8.2500', '', '', ''],
        ];
    }

    /**
     * Stub the rate collection so it reports the given codes as already existing.
     *
     * @param string[] $codes
     */
    private function givenExistingRateCodes(array $codes): void
    {
        $rates = [];
        foreach ($codes as $code) {
            $rates[] = $this->givenRate(['code' => $code]);
        }
        $this->givenCollection($rates);
    }

    /**
     * Build a tax-rate-like entity. The real Rate model resolves its getters via
     * the magic __call/getData mechanism, which a strict PHPUnit mock cannot stub;
     * an anonymous object reproduces exactly the surface the component touches.
     *
     * @param array<string, mixed> $values
     */
    private function givenRate(array $values): object
    {
        return new class ($values) {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function getCode(): string
            {
                return (string) ($this->values['code'] ?? '');
            }

            public function getTaxCountryId(): string
            {
                return (string) ($this->values['tax_country_id'] ?? '');
            }

            public function getTaxRegionId(): mixed
            {
                return $this->values['tax_region_id'] ?? '';
            }

            public function getRate(): mixed
            {
                return $this->values['rate'] ?? 0;
            }

            public function getTaxPostcode(): string
            {
                return (string) ($this->values['tax_postcode'] ?? '');
            }

            public function getZipIsRange(): mixed
            {
                return $this->values['zip_is_range'] ?? '';
            }

            public function getZipFrom(): mixed
            {
                return $this->values['zip_from'] ?? '';
            }

            public function getZipTo(): mixed
            {
                return $this->values['zip_to'] ?? '';
            }

            public function getData(string $key): mixed
            {
                return $this->values[$key] ?? null;
            }
        };
    }

    /**
     * Build a collection that iterates the given rates and answers addFieldToFilter
     * fluently, then wire the factory to return it.
     *
     * @param array $rates
     */
    private function givenCollection(array $rates): void
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getIterator'])
            ->getMock();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rates));
        $collection->method('addFieldToFilter')->willReturnSelf();
        $this->rateFactory->method('create')->willReturn($this->wrapCollection($collection));
    }

    /**
     * The component calls $this->rateFactory->create()->getCollection(); wrap the
     * collection in a Rate model whose getCollection() returns it.
     */
    private function wrapCollection(Collection&MockObject $collection): Rate&MockObject
    {
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollection'])
            ->getMock();
        $rate->method('getCollection')->willReturn($collection);

        return $rate;
    }
}
