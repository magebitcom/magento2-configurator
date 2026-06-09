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
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\TaxImportExport\Model\Rate\CsvImportHandler;

class TaxRates implements ComponentInterface, ExportableComponentInterface
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

    /**
     * Export tax rates from `tax_calculation_rate` into the CSV source format (a
     * list of rows, the first being the header). Refresh mode rewrites only the
     * rate codes already tracked in the source file, rebuilding each row from its
     * current DB values; rates no longer present in the DB keep their existing
     * row. Full mode dumps every rate (optionally filtered by a code prefix).
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild only the rows whose code is already tracked in the source file,
     * reading current values from the DB. Codes that no longer exist in the DB
     * keep their existing row unchanged. The header row is preserved.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        if (!isset($existing[0]) || !is_array($existing[0])) {
            return $existing;
        }

        $header = array_values((array) $existing[0]);
        $dataRows = array_slice($existing, 1);

        // Collect the tracked codes and load their current DB rows in one query.
        $codes = [];
        foreach ($dataRows as $row) {
            $assoc = $this->rowToAssoc($header, (array) $row);
            $code = (string) ($assoc['code'] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        $current = $this->loadRatesByCode($codes);

        $out = [$header];
        foreach ($dataRows as $row) {
            $row = (array) $row;
            $assoc = $this->rowToAssoc($header, $row);
            $code = strtolower((string) ($assoc['code'] ?? ''));

            if ($code !== '' && isset($current[$code])) {
                // Rebuild the row from current DB values, in this file's header order.
                $out[] = $this->buildRow($header, $current[$code]);
                continue;
            }

            // Not in the DB any more -> keep the existing row untouched.
            $out[] = array_values($row);
        }

        return $out;
    }

    /**
     * Dump every tax rate from the DB in the documented source format. When a
     * filter is set, only rates whose code starts with it are exported.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $header = ['code', 'tax_country_id', 'tax_region_id', 'rate', 'tax_postcode', 'zip_is_range', 'zip_from', 'zip_to'];

        $collection = $this->rateFactory->create()->getCollection();
        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter('code', ['like' => $filter . '%']);
        }

        $out = [$header];
        foreach ($collection as $rate) {
            $out[] = $this->buildRow($header, $this->rateToValues($rate));
        }

        return $out;
    }

    /**
     * Load the given rate codes from the DB, keyed by lowercased code, each value
     * being the field=>value map produced by rateToValues().
     *
     * @param string[] $codes
     * @return array<string, array<string, string>>
     */
    private function loadRatesByCode(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes, static fn (string $c): bool => $c !== '')));
        if ($codes === []) {
            return [];
        }

        $collection = $this->rateFactory->create()->getCollection()->addFieldToFilter('code', ['in' => $codes]);

        $rates = [];
        foreach ($collection as $rate) {
            $rates[strtolower((string) $rate->getCode())] = $this->rateToValues($rate);
        }

        return $rates;
    }

    /**
     * Map a loaded tax rate model to the source-format field=>value pairs. The
     * rate collection joins the region code, so prefer that (e.g. `CA`); fall back
     * to the numeric region id. The rate is normalised to four decimals to match
     * the canonical CSV format.
     *
     * @param mixed $rate
     * @return array<string, string>
     */
    private function rateToValues(mixed $rate): array
    {
        $regionCode = (string) ($rate->getData('region_code') ?? '');
        $regionId = (string) ($rate->getTaxRegionId() ?? '');
        $region = $regionCode !== '' && $regionCode !== '0' ? $regionCode : $regionId;
        if ($region === '0') {
            $region = '';
        }

        $postcode = (string) ($rate->getTaxPostcode() ?? '');
        if ($postcode === '') {
            $postcode = '*';
        }

        $zipIsRange = (string) ($rate->getZipIsRange() ?? '');
        if ($zipIsRange === '0') {
            $zipIsRange = '';
        }

        return [
            'code' => (string) $rate->getCode(),
            'tax_country_id' => (string) $rate->getTaxCountryId(),
            'tax_region_id' => $region,
            'rate' => number_format((float) $rate->getRate(), 4, '.', ''),
            'tax_postcode' => $postcode,
            'zip_is_range' => $zipIsRange,
            'zip_from' => $zipIsRange !== '' ? (string) ($rate->getZipFrom() ?? '') : '',
            'zip_to' => $zipIsRange !== '' ? (string) ($rate->getZipTo() ?? '') : '',
        ];
    }

    /**
     * Emit a row of values in the given header's column order, defaulting missing
     * columns to an empty string.
     *
     * @param string[] $header
     * @param array<string, string> $values
     * @return string[]
     */
    private function buildRow(array $header, array $values): array
    {
        $row = [];
        foreach ($header as $column) {
            $row[] = $values[$column] ?? '';
        }

        return $row;
    }

    /**
     * Re-key a positional data row by the header columns.
     *
     * @param string[] $header
     * @param array $row
     * @return array<string, string>
     */
    private function rowToAssoc(array $header, array $row): array
    {
        $row = array_values($row);
        $assoc = [];
        foreach ($header as $i => $column) {
            $assoc[$column] = (string) ($row[$i] ?? '');
        }

        return $assoc;
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
