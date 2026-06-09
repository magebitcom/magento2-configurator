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
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\TaxImportExport\Model\Rate\CsvImportHandler;

class TaxRates implements ComponentInterface
{
    private const ALIAS = 'taxrates';
    private const DESCRIPTION = 'Component to create Tax Rates';

    public function __construct(
        private readonly CsvImportHandler $csvImportHandler,
        private readonly LoggerInterface $log,
        private readonly RateFactory $rateFactory
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data[0])) {
            $result->addError('No row data found.');
            return $result;
        }

        try {
            // Sort data into the column order importExport requires
            $sortedData = $this->getSortedData($data);

            // Row-level reconciliation: in create mode drop rows whose rate code
            // already exists (a version bump forces a full re-import; maintain
            // re-imports). Done before the dry-run branch so counts are accurate.
            if ($context->getMode() === ComponentMode::Create && $context->getVersion() === null) {
                $sortedData = $this->dropExistingRates($sortedData, $result);
            }

            // Only the header row remains -> nothing new to import.
            if (count($sortedData) <= 1) {
                $this->log->logInfo('No new tax rates to import (all already exist in create mode).');
                return $result;
            }

            if ($context->isDryRun()) {
                // Bulk CSV import: skip both the temp-file write and the import.
                $this->log->logInfo('[dry-run] Would import tax rates from a generated CSV file.');
                return $result;
            }

            // Generate sorted csv file
            $tmpFile = $this->getTmpFile($sortedData);

            // Pass the temporary file name to the import handler
            $this->csvImportHandler->importFromCsvFile(['tmp_name' => $tmpFile]);

            // Remove the temporary file
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            unlink($tmpFile);

            // We don't know how many were successfully imported so we can't log the
            // number of records imported, but we can log that the import was successful.
            $this->log->logInfo('Tax rates finished importing, check the rates in the admin panel.');
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * Reorder each row into the fixed column order Magento's CsvImportHandler expects.
     *
     * @param array $data
     * @return array
     */
    protected function getSortedData(array $data): array
    {
        $sortedData = [];

        foreach ($data as $index => $rate) {
            if ($index === 0) {
                $sortedData[] = $rate;
                continue; // Skip the header row
            }

            $relativeData = array_combine($data[0], $rate);

            // Reorder the data to match the format the importer requires
            $rateData = [
                $relativeData['code'],
                $relativeData['tax_country_id'],
                $relativeData['tax_region_id'],
                $relativeData['tax_postcode'],
                $relativeData['rate'],
                $relativeData['zip_is_range'],
                $relativeData['zip_from'],
                $relativeData['zip_to']
            ];
            $sortedData[] = $rateData;
        }
        return $sortedData;
    }

    /**
     * Drop sorted-data rows whose tax rate code already exists. The first column
     * of each data row is the code; the header row (index 0) is always kept.
     *
     * @param array $sortedData
     * @param ComponentResult $result
     * @return array
     */
    protected function dropExistingRates(array $sortedData, ComponentResult $result): array
    {
        if (count($sortedData) <= 1) {
            return $sortedData;
        }

        $header = $sortedData[0];
        $rows = array_slice($sortedData, 1);

        $codes = array_map(static fn (array $row): string => (string) ($row[0] ?? ''), $rows);
        $existing = $this->loadExistingRateCodes($codes);
        if ($existing === []) {
            return $sortedData;
        }

        $kept = [$header];
        $dropped = 0;
        foreach ($rows as $row) {
            $code = strtolower((string) ($row[0] ?? ''));
            if ($code !== '' && isset($existing[$code])) {
                $result->recordSkipped();
                $dropped++;
                continue;
            }
            $kept[] = $row;
        }

        if ($dropped > 0) {
            $this->log->logInfo(sprintf('Create mode: %d existing tax rate(s) skipped.', $dropped));
        }

        return $kept;
    }

    /**
     * Load the subset of given rate codes that already exist, lowercased.
     *
     * @param string[] $codes
     * @return array<string, true>
     */
    private function loadExistingRateCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes, static fn (string $c): bool => $c !== '')));
        if ($codes === []) {
            return [];
        }

        $collection = $this->rateFactory->create()->getCollection()->addFieldToFilter('code', ['in' => $codes]);

        $existing = [];
        foreach ($collection as $rate) {
            $existing[strtolower((string) $rate->getCode())] = true;
        }

        return $existing;
    }

    /**
     * Write the sorted data to a temporary CSV file and return its path.
     *
     * @param array $sortedData
     * @return string
     */
    protected function getTmpFile(array $sortedData): string
    {
        // Define a temporary file name
        $tmpFile = sys_get_temp_dir() . '/tax_rates_' . uniqid() . '.csv';

        // Write the CSV data to the temporary file
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $fileHandle = fopen($tmpFile, 'w');
        foreach ($sortedData as $line) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            fputcsv($fileHandle, $line, escape: '');
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        fclose($fileHandle);

        // Return the path to the temporary file
        return $tmpFile;
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
