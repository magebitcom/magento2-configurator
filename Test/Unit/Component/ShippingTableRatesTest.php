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
use Magebit\Configurator\Component\ShippingTableRates;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\TablerateFactory;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShippingTableRatesTest extends TestCase
{
    private TablerateFactory&MockObject $tablerateFactory;
    private WebsiteFactory&MockObject $websiteFactory;
    private RegionFactory&MockObject $regionFactory;
    private LoggerInterface&MockObject $log;
    private Tablerate&MockObject $tablerateModel;
    private AdapterInterface&MockObject $connection;
    private ShippingTableRates $component;

    protected function setUp(): void
    {
        $this->tablerateFactory = $this->getMockBuilder(TablerateFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->websiteFactory = $this->getMockBuilder(WebsiteFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->regionFactory = $this->getMockBuilder(RegionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->log = $this->createMock(LoggerInterface::class);

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->tablerateModel = $this->createMock(Tablerate::class);
        $this->tablerateModel->method('getConnection')->willReturn($this->connection);
        $this->tablerateModel->method('getMainTable')->willReturn('shipping_tablerate');
        $this->tablerateFactory->method('create')->willReturn($this->tablerateModel);

        $this->component = new ShippingTableRates(
            $this->tablerateFactory,
            $this->websiteFactory,
            $this->regionFactory,
            $this->log
        );
    }

    public function testCreatesShippingRateWhenWebsiteExists(): void
    {
        $this->givenWebsite('base', 1);
        $this->givenRegionResolvesTo('NY', 'US', 43);

        // The DB insert happens once, with the website/region ids merged in and
        // the YAML-only keys stripped.
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'shipping_tablerate',
                $this->callback(function (array $rows): bool {
                    $row = $rows[0];
                    return $row['website_id'] === 1
                        && $row['dest_region_id'] === 43
                        && $row['dest_country_id'] === 'US'
                        && !array_key_exists('dest_region_code', $row)
                        && !array_key_exists('website_code', $row);
                }),
                $this->isType('array')
            );

        $result = $this->execute([
            'base' => [
                [
                    'dest_country_id' => 'US',
                    'dest_region_code' => 'NY',
                    'dest_zip' => '*',
                    'condition_name' => 'package_weight',
                    'condition_value' => 0,
                    'price' => 5.0,
                    'cost' => 0,
                ],
            ],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testUnresolvedRegionDefaultsToZero(): void
    {
        $this->givenWebsite('base', 1);
        $this->givenRegionResolvesTo('*', 'US', null);

        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'shipping_tablerate',
                $this->callback(static fn (array $rows): bool => $rows[0]['dest_region_id'] === 0),
                $this->isType('array')
            );

        $result = $this->execute([
            'base' => [
                [
                    'dest_country_id' => 'US',
                    'dest_region_code' => '*',
                    'dest_zip' => '*',
                    'condition_name' => 'package_weight',
                    'condition_value' => 0,
                    'price' => 5.0,
                    'cost' => 0,
                ],
            ],
        ]);

        $this->assertSame(1, $result->getCreated());
    }

    public function testCreatesMultipleRatesForWebsite(): void
    {
        $this->givenWebsite('base', 1);
        $this->givenRegionResolvesTo('NY', 'US', 43);

        $this->connection->expects($this->exactly(2))->method('insertOnDuplicate');

        $row = [
            'dest_country_id' => 'US',
            'dest_region_code' => 'NY',
            'dest_zip' => '*',
            'condition_name' => 'package_weight',
            'condition_value' => 0,
            'price' => 5.0,
            'cost' => 0,
        ];

        $result = $this->execute(['base' => [$row, $row]]);

        $this->assertSame(2, $result->getCreated());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenWebsite('base', 1);
        $this->givenRegionResolvesTo('NY', 'US', 43);

        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $result = $this->execute([
            'base' => [
                [
                    'dest_country_id' => 'US',
                    'dest_region_code' => 'NY',
                    'dest_zip' => '*',
                    'condition_name' => 'package_weight',
                    'condition_value' => 0,
                    'price' => 5.0,
                    'cost' => 0,
                ],
            ],
        ], true);

        // Dry-run still records intent.
        $this->assertSame(1, $result->getCreated());
    }

    public function testSkipsAllRatesWhenWebsiteMissing(): void
    {
        // Website code resolves to no id -> component logs an error and bails.
        $this->givenWebsite('does_not_exist', null);

        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->log->expects($this->once())->method('logError');

        $result = $this->execute([
            'does_not_exist' => [
                [
                    'dest_country_id' => 'US',
                    'dest_region_code' => 'NY',
                    'dest_zip' => '*',
                    'condition_name' => 'package_weight',
                    'condition_value' => 0,
                    'price' => 5.0,
                    'cost' => 0,
                ],
            ],
        ]);

        $this->assertSame(0, $result->getCreated());
        // A missing website is logged but does not add a structured error.
        $this->assertTrue($result->isSuccessful());
    }

    public function testRecordsErrorWhenDataEmpty(): void
    {
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testFullExportDumpsEveryWebsiteRow(): void
    {
        $this->givenSelect();
        $this->givenWebsiteCode(1, 'base');
        $this->givenRegionCode(43, 'NY');

        $this->connection->method('fetchAll')->willReturn([
            [
                'website_id' => '1',
                'dest_country_id' => 'US',
                'dest_region_id' => '43',
                'dest_zip' => '*',
                'condition_name' => 'package_weight',
                'condition_value' => '0.0000',
                'price' => '5.0000',
                'cost' => '0.0000',
            ],
        ]);

        $out = $this->component->export(new ExportContext([], true, null, false));

        $this->assertArrayHasKey('base', $out);
        $this->assertCount(1, $out['base']);
        $row = $out['base'][0];
        $this->assertSame('US', $row['dest_country_id']);
        $this->assertSame('NY', $row['dest_region_code']);
        // Numeric columns are normalised back to numbers. '0.0000' + 0 yields
        // a float (0.0), not an int, so the wildcard-free cost stays a float.
        $this->assertSame(5.0, $row['price']);
        $this->assertSame(0.0, $row['cost']);
        $this->assertArrayNotHasKey('website_id', $row);
    }

    public function testFullExportMapsZeroRegionToWildcard(): void
    {
        $this->givenSelect();
        $this->givenWebsiteCode(1, 'base');

        $this->connection->method('fetchAll')->willReturn([
            [
                'website_id' => '1',
                'dest_country_id' => 'US',
                'dest_region_id' => '0',
                'dest_zip' => '*',
                'condition_name' => 'package_weight',
                'condition_value' => '0.0000',
                'price' => '5.0000',
                'cost' => '0.0000',
            ],
        ]);

        $out = $this->component->export(new ExportContext([], true, null, false));

        $this->assertSame('*', $out['base'][0]['dest_region_code']);
    }

    public function testRefreshExportRebuildsTrackedWebsiteFromDb(): void
    {
        $this->givenSelect();
        $this->givenWebsite('base', 1);
        $this->givenRegionCode(43, 'NY');

        $this->connection->method('fetchAll')->willReturn([
            [
                'dest_country_id' => 'US',
                'dest_region_id' => '43',
                'dest_zip' => '*',
                'condition_name' => 'package_weight',
                'condition_value' => '0.0000',
                'price' => '7.5000',
                'cost' => '0.0000',
            ],
        ]);

        $existing = ['base' => [['dest_country_id' => 'US', 'price' => 1.0]]];
        $out = $this->component->export(new ExportContext($existing, false, null, false));

        $this->assertArrayHasKey('base', $out);
        // DB rows replace the stale tracked entry.
        $this->assertSame(7.5, $out['base'][0]['price']);
        $this->assertSame('NY', $out['base'][0]['dest_region_code']);
    }

    public function testRefreshExportKeepsTrackedEntryWhenWebsiteUnresolved(): void
    {
        // Tracked website code no longer resolves to an id -> keep its entry as-is.
        $this->givenWebsite('gone', null);

        $existing = ['gone' => [['dest_country_id' => 'US', 'price' => 1.0]]];
        $out = $this->component->export(new ExportContext($existing, false, null, false));

        $this->assertSame($existing, $out);
    }

    /**
     * @param array $shippingTableRates value of the source data array (keyed by website code)
     */
    private function execute(array $shippingTableRates, bool $dryRun = false): ComponentResult
    {
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'test',
            $dryRun,
            static fn (): array => $shippingTableRates
        );

        return $this->component->execute($context);
    }

    private function givenWebsite(string $code, ?int $id): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn($id);
        $website->method('load')->willReturnSelf();
        $this->websiteFactory->method('create')->willReturn($website);
    }

    private function givenWebsiteCode(int $id, string $code): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn($id);
        $website->method('getCode')->willReturn($code);
        $website->method('load')->willReturnSelf();
        $this->websiteFactory->method('create')->willReturn($website);
    }

    private function givenRegionResolvesTo(string $code, string $countryId, ?int $regionId): void
    {
        $region = $this->createMock(Region::class);
        $region->method('getId')->willReturn($regionId);
        $region->method('loadByCode')->willReturnSelf();
        $this->regionFactory->method('create')->willReturn($region);
    }

    private function givenRegionCode(int $regionId, string $code): void
    {
        // getCode() on Region is a magic data-model getter, so it must be
        // declared via addMethods(); load()/getId() are real declared methods.
        $region = $this->getMockBuilder(Region::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCode'])
            ->onlyMethods(['getId', 'load'])
            ->getMock();
        $region->method('getId')->willReturn($regionId);
        $region->method('getCode')->willReturn($code);
        $region->method('load')->willReturnSelf();
        $this->regionFactory->method('create')->willReturn($region);
    }

    private function givenSelect(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
    }
}
