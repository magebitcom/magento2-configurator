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
use Magebit\Configurator\Component\Product\AttributeOption;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Import\ImporterFactory;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TieredPrices implements ComponentInterface
{
    const SKU_COLUMN_HEADING = 'sku';
    const SEPARATOR = ';';

    private const ALIAS = 'tiered_prices';
    private const DESCRIPTION = 'Component to import tiered prices using a CSV file.';

    /** @var string[] */
    private array $successPrices = [];

    /** @var string[] */
    private array $skippedPrices = [];

    private int|false $skuColumn = false;

    public function __construct(
        private readonly ImporterFactory $importerFactory,
        private readonly AttributeOption $attributeOption,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        // Get the first row of the CSV file for the attribute columns.
        if (!isset($data[0])) {
            $result->addError('The row data is not valid.');
            return $result;
        }
        $attributeKeys = $this->getAttributesFromCsv($data);
        $this->skuColumn = $this->getSkuColumnIndex($attributeKeys);
        $totalColumnCount = count($attributeKeys);
        unset($data[0]);

        $pricesArray = [];

        foreach ($data as $tieredPrice) {
            if (count($tieredPrice) !== $totalColumnCount) {
                $this->skippedPrices[] = $tieredPrice[$this->skuColumn];
                continue;
            }
            $priceArray = [];
            foreach ($attributeKeys as $column => $code) {
                $priceArray[$code] = $tieredPrice[$column];
                $this->attributeOption->processAttributeValues($code, $priceArray[$code]);
            }
            $pricesArray[] = $priceArray;
            $this->successPrices[] = $tieredPrice[$this->skuColumn];
        }

        if (count($this->skippedPrices) > 0) {
            $this->log->logInfo(
                sprintf(
                    'The following tiered prices were skipped as they do not have the required columns: '
                    .PHP_EOL.'%s',
                    implode(PHP_EOL, $this->skippedPrices)
                )
            );
            $result->recordSkipped(count($this->skippedPrices));
        }

        $rowCount = count($this->successPrices);

        if ($context->isDryRun()) {
            $this->log->logInfo(sprintf('[dry-run] Would import %s rows', $rowCount));
            $result->recordCreated($rowCount);
            return $result;
        }

        $this->log->logInfo(sprintf('Attempting to import %s rows', $rowCount));
        try {
            $import = $this->importerFactory->create();
            $import->setEntityCode('advanced_pricing');
            $import->setMultipleValueSeparator(self::SEPARATOR);
            $import->processImport($pricesArray);
            $this->log->logInfo($import->getLogTrace());
            $this->log->logError($import->getErrorMessages());
            $result->recordCreated($rowCount);
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * Gets the first row of the CSV file as these should be the attribute keys
     *
     * @param array $data
     * @return array
     */
    public function getAttributesFromCsv(array $data): array
    {
        $attributes = [];
        foreach ($data[0] as $attributeCode) {
            $attributes[] = $attributeCode;
        }
        return $attributes;
    }

    /**
     * Get the column index of the SKU
     *
     * @param array $headers
     * @return int|false
     */
    public function getSkuColumnIndex(array $headers): int|false
    {
        return array_search(self::SKU_COLUMN_HEADING, $headers);
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
