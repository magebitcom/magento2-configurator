<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\TablerateFactory;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate;
use Magento\Store\Model\WebsiteFactory;
use Magento\Store\Model\Website;
use Magento\Directory\Model\RegionFactory;
use Magento\Directory\Model\Region;

class ShippingTableRates implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'shippingtablerates';
    private const DESCRIPTION = 'Component to create and maintain Shipping Table Rates';

    public function __construct(
        private readonly TablerateFactory $tablerateFactory,
        private readonly WebsiteFactory $websiteFactory,
        private readonly RegionFactory $regionFactory,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * This method should be used to process the data and populate the Magento Database.
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if ($data === []) {
            $result->addError('No shipping table rate data found in the source data.');
            return $result;
        }

        /** @var Tablerate $tablerateModel */
        $tablerateModel = $this->tablerateFactory->create();

        $shippingRateCount = 1;
        foreach ($data as $website => $shippingRates) {

            /** @var Website $websiteModel */
            $websiteModel = $this->websiteFactory->create();
            $websiteModel->load($website, 'code');
            $websiteId = $websiteModel->getId();

            if (!$websiteId) {
                $this->log->logError(sprintf("No website exists for code '%s'. Skipping.", $website));
                return $result;
            }

            foreach ($shippingRates as $shippingRate) {
                $this->createNewShippingTableRate(
                    $shippingRate,
                    (int) $websiteId,
                    $shippingRateCount,
                    (string) $website,
                    $tablerateModel,
                    $context->isDryRun(),
                    $result
                );
                $shippingRateCount++;
            }
        }

        return $result;
    }

    /**
     * @param array $shippingRate
     * @param Tablerate $tablerateModel
     */
    private function createNewShippingTableRate(
        array $shippingRate,
        int $websiteId,
        int $shippingRateCount,
        string $website,
        Tablerate $tablerateModel,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $columns = [
            'website_id',
            'dest_region_id',
            'dest_country_id',
            'dest_zip',
            'condition_name',
            'condition_value',
            'price',
            'cost'
        ];

        /** @var Region $regionModel */
        $regionModel = $this->regionFactory->create();
        $regionModel = $regionModel->loadByCode($shippingRate['dest_region_code'], $shippingRate['dest_country_id']);
        $regionId = $regionModel->getId();
        if ($regionId === null) {
            $regionId = 0;
        }

        $this->removeYamlKeysFromDatabaseInsert($shippingRate);

        $shippingRate = array_merge(
            [
                'website_id' => $websiteId,
                'dest_region_id' => $regionId
            ],
            $shippingRate
        );

        if ($dryRun) {
            $this->log->logInfo(
                sprintf(
                    "[dry-run] Would create shipping rate #%s for website %s",
                    $shippingRateCount,
                    $website
                )
            );
            $result->recordCreated();
            return;
        }

        $this->log->logInfo(
            sprintf(
                "Shipping rate #%s for website %s being created",
                $shippingRateCount,
                $website
            )
        );
        $tablerateModel->getConnection()
            ->insertOnDuplicate($tablerateModel->getMainTable(), [$shippingRate], $columns);

        $result->recordCreated();
    }

    /**
     * @param array $shippingRate
     */
    private function removeYamlKeysFromDatabaseInsert(array &$shippingRate): void
    {
        unset($shippingRate['dest_region_code']);
        unset($shippingRate['website_code']);
    }

    /**
     * Export current shipping_tablerate rows into the source format. Refresh mode
     * rebuilds only the website codes already tracked in the source file (keeping
     * a tracked website's existing entry untouched if it has no rows in the DB);
     * full mode dumps every website's rows, optionally limited to a single website
     * code via the filter.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild the rate rows for each website code already present in the source
     * file, reading their current values from the DB. A tracked website that no
     * longer has any rows in the DB keeps its existing entry unchanged.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        $out = [];
        foreach ($existing as $code => $entries) {
            $websiteId = $this->resolveWebsiteId((string) $code);
            if ($websiteId === null) {
                $out[$code] = $entries;
                continue;
            }

            $rows = $this->fetchRows($websiteId);
            $out[$code] = $rows === [] ? $entries : $rows;
        }

        return $out;
    }

    /**
     * Dump every website's rate rows from the DB. If a filter is given it is
     * treated as a website code and only that website is exported.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        /** @var Tablerate $tablerateModel */
        $tablerateModel = $this->tablerateFactory->create();
        $connection = $tablerateModel->getConnection();

        $select = $connection->select()
            ->from($tablerateModel->getMainTable())
            ->order('website_id')
            ->order('pk');

        if ($filter !== null && $filter !== '') {
            $websiteId = $this->resolveWebsiteId($filter);
            if ($websiteId === null) {
                return [];
            }
            $select->where('website_id = ?', $websiteId);
        }

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $code = $this->resolveWebsiteCode((int) $row['website_id']);
            if ($code === null) {
                continue;
            }
            $out[$code][] = $this->mapRow($row);
        }

        return $out;
    }

    /**
     * Fetch the rate rows for a single website id in the source-row format.
     *
     * @param int $websiteId
     * @return array
     */
    private function fetchRows(int $websiteId): array
    {
        /** @var Tablerate $tablerateModel */
        $tablerateModel = $this->tablerateFactory->create();
        $connection = $tablerateModel->getConnection();

        $select = $connection->select()
            ->from($tablerateModel->getMainTable())
            ->where('website_id = ?', $websiteId)
            ->order('pk');

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    /**
     * Map a DB row back to the documented source-row format, resolving the
     * region id back to its code (0 / unresolved => "*").
     *
     * @param array $row
     * @return array
     */
    private function mapRow(array $row): array
    {
        return [
            'dest_country_id' => $row['dest_country_id'],
            'dest_region_code' => $this->resolveRegionCode((int) $row['dest_region_id']),
            'dest_zip' => $row['dest_zip'],
            'condition_name' => $row['condition_name'],
            'condition_value' => $row['condition_value'] + 0,
            'price' => $row['price'] + 0,
            'cost' => $row['cost'] + 0,
        ];
    }

    private function resolveWebsiteId(string $code): ?int
    {
        /** @var Website $website */
        $website = $this->websiteFactory->create();
        $website->load($code, 'code');

        return $website->getId() ? (int) $website->getId() : null;
    }

    private function resolveWebsiteCode(int $websiteId): ?string
    {
        /** @var Website $website */
        $website = $this->websiteFactory->create();
        $website->load($websiteId);

        return $website->getId() ? (string) $website->getCode() : null;
    }

    /**
     * Resolve a region id back to its region code. A 0 (or unresolved) id maps
     * to "*", matching the wildcard used in the source format.
     */
    private function resolveRegionCode(int $regionId): string
    {
        if ($regionId === 0) {
            return '*';
        }

        /** @var Region $region */
        $region = $this->regionFactory->create();
        $region->load($regionId);

        $code = $region->getId() ? (string) $region->getCode() : '';

        return $code !== '' ? $code : '*';
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
