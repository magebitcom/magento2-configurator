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
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\TablerateFactory;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate;
use Magento\Store\Model\WebsiteFactory;
use Magento\Store\Model\Website;
use Magento\Directory\Model\RegionFactory;
use Magento\Directory\Model\Region;

class ShippingTableRates implements ComponentInterface
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
