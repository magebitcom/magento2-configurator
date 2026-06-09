<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use FireGento\FastSimpleImport\Model\Importer;
use FireGento\FastSimpleImport\Model\ImporterFactory;
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Component\Product\AttributeOption;
use Magebit\Configurator\Component\TieredPrices;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TieredPricesTest extends TestCase
{
    private ImporterFactory&MockObject $importerFactory;
    private AttributeOption&MockObject $attributeOption;
    private LoggerInterface&MockObject $log;
    private TieredPrices $component;

    protected function setUp(): void
    {
        $this->importerFactory = $this->getMockBuilder(ImporterFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->attributeOption = $this->createMock(AttributeOption::class);
        $this->log = $this->createMock(LoggerInterface::class);

        $this->component = new TieredPrices(
            $this->importerFactory,
            $this->attributeOption,
            $this->log
        );
    }

    public function testImportsValidRows(): void
    {
        // One header row + two data rows -> two rows handed to the importer.
        $importer = $this->givenImporter();
        $importer->expects($this->once())->method('setEntityCode')->with('advanced_pricing');
        $importer->expects($this->once())->method('setMultipleValueSeparator')->with(';');
        $importer->expects($this->once())->method('processImport')->with($this->callback(
            static fn (array $rows): bool => count($rows) === 2
                && $rows[0]['sku'] === 'sku-1'
                && $rows[1]['sku'] === 'sku-2'
        ));

        $result = $this->execute($this->validData());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getCreated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testProcessesAttributeOptionsForEachCell(): void
    {
        $this->givenImporter();

        // Every cell of every data row is fed to the option processor (3 columns x 2 rows).
        $this->attributeOption->expects($this->exactly(6))->method('processAttributeValues');

        $result = $this->execute($this->validData());

        $this->assertSame(2, $result->getCreated());
    }

    public function testSkipsRowsWithWrongColumnCount(): void
    {
        $importer = $this->givenImporter();
        // Only the single well-formed row should reach the importer.
        $importer->expects($this->once())->method('processImport')->with($this->callback(
            static fn (array $rows): bool => count($rows) === 1 && $rows[0]['sku'] === 'good'
        ));

        $data = [
            0 => ['sku', 'tier_website', 'price'],
            1 => ['good', 'All Websites', '9.99'],
            2 => ['malformed', 'All Websites'], // missing the price column
        ];

        $result = $this->execute($data);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotImportButRecordsIntent(): void
    {
        // Dry-run must never touch the importer factory.
        $this->importerFactory->expects($this->never())->method('create');
        $this->log->expects($this->atLeastOnce())->method('logInfo');

        $result = $this->execute($this->validData(), true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getCreated());
    }

    public function testErrorWhenHeaderRowMissing(): void
    {
        // No row index 0 -> guard fails before any importing happens.
        $this->importerFactory->expects($this->never())->method('create');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
        $this->assertSame(0, $result->getCreated());
    }

    public function testRecordsErrorWhenImporterThrows(): void
    {
        $importer = $this->givenImporter();
        $importer->method('processImport')->willThrowException(new \Exception('import blew up'));

        $result = $this->execute($this->validData());

        $this->assertFalse($result->isSuccessful());
        $this->assertContains('import blew up', $result->getErrors());
        // The row count is only recorded on success, so nothing is reported as created.
        $this->assertSame(0, $result->getCreated());
    }

    public function testHeaderHelpersResolveSkuColumn(): void
    {
        $headers = $this->component->getAttributesFromCsv([0 => ['tier_website', 'sku', 'price']]);

        $this->assertSame(['tier_website', 'sku', 'price'], $headers);
        $this->assertSame(1, $this->component->getSkuColumnIndex($headers));
        $this->assertFalse($this->component->getSkuColumnIndex(['price', 'qty']));
    }

    /**
     * Header row plus two valid three-column data rows.
     *
     * @return array<int, array<int, string>>
     */
    private function validData(): array
    {
        return [
            0 => ['sku', 'tier_website', 'price'],
            1 => ['sku-1', 'All Websites', '9.99'],
            2 => ['sku-2', 'All Websites', '8.50'],
        ];
    }

    /**
     * Builds an Importer the factory will hand back, with the success-path getters stubbed.
     */
    private function givenImporter(): Importer&MockObject
    {
        $importer = $this->getMockBuilder(Importer::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setEntityCode',
                'setMultipleValueSeparator',
                'processImport',
                'getLogTrace',
                'getErrorMessages',
            ])
            ->getMock();
        $importer->method('getLogTrace')->willReturn('');
        $importer->method('getErrorMessages')->willReturn([]);

        $this->importerFactory->method('create')->willReturn($importer);

        return $importer;
    }

    /**
     * @param array<int, array<int, string>> $rows raw CSV rows (index 0 is the header)
     */
    private function execute(array $rows, bool $dryRun = false): ComponentResult
    {
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'test',
            $dryRun,
            static fn (): array => $rows
        );

        return $this->component->execute($context);
    }
}
