<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model\Import;

use Magebit\Configurator\Model\Import\Source\ArrayAdapterFactory;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ImportFactory;

/**
 * Thin wrapper around Magento's native ImportExport framework that imports an
 * array of rows instead of a CSV file on disk.
 *
 * This replaces firegento/fastsimpleimport, which was a convenience layer over
 * the same Magento\ImportExport classes but is distributed under GPL-3.0 and so
 * imposed copyleft terms on anything that shipped alongside it. The behaviour
 * here is dictated by Magento's framework, not by FireGento's implementation:
 * build an {@see ArrayAdapter} source, validate it, import it, and invalidate
 * the related indexers — exactly what the admin "Import" flow does.
 */
class Importer
{
    private ?Import $importModel = null;

    private string $entityCode = 'catalog_product';

    private string $behavior = Import::BEHAVIOR_APPEND;

    private string $multipleValueSeparator = Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR;

    private string $validationStrategy = ProcessingErrorAggregatorInterface::VALIDATION_STRATEGY_SKIP_ERRORS;

    private int $allowedErrorCount = 10;

    /** @var string[] */
    private array $errorMessages = [];

    public function __construct(
        private readonly ImportFactory $importFactory,
        private readonly ArrayAdapterFactory $arrayAdapterFactory
    ) {
    }

    /**
     * Set the import entity code (e.g. catalog_product, customer_composite, advanced_pricing).
     *
     * @param string $entityCode
     * @return $this
     */
    public function setEntityCode(string $entityCode): self
    {
        $this->entityCode = $entityCode;
        if ($this->importModel !== null) {
            $this->importModel->setData('entity', $entityCode);
        }

        return $this;
    }

    /**
     * Set the import behaviour (append, add_update, replace, delete).
     *
     * @param string $behavior
     * @return $this
     */
    public function setBehavior(string $behavior): self
    {
        $this->behavior = $behavior;
        if ($this->importModel !== null) {
            $this->importModel->setData('behavior', $behavior);
        }

        return $this;
    }

    /**
     * Set the separator used between several values packed into a single cell.
     *
     * @param string $separator
     * @return $this
     */
    public function setMultipleValueSeparator(string $separator): self
    {
        $this->multipleValueSeparator = $separator;
        if ($this->importModel !== null) {
            $this->importModel->setData(Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR, $separator);
        }

        return $this;
    }

    /**
     * @param string $validationStrategy
     * @return $this
     */
    public function setValidationStrategy(string $validationStrategy): self
    {
        $this->validationStrategy = $validationStrategy;
        if ($this->importModel !== null) {
            $this->importModel->setData(Import::FIELD_NAME_VALIDATION_STRATEGY, $validationStrategy);
        }

        return $this;
    }

    /**
     * @param int $allowedErrorCount
     * @return $this
     */
    public function setAllowedErrorCount(int $allowedErrorCount): self
    {
        $this->allowedErrorCount = $allowedErrorCount;
        if ($this->importModel !== null) {
            $this->importModel->setData(Import::FIELD_NAME_ALLOWED_ERROR_COUNT, $allowedErrorCount);
        }

        return $this;
    }

    /**
     * Lazily build the underlying Magento import model, seeded with the
     * currently configured entity/behaviour/separator.
     *
     * @return Import
     */
    public function getImportModel(): Import
    {
        if ($this->importModel === null) {
            $importModel = $this->importFactory->create();
            $importModel->setData([
                'entity' => $this->entityCode,
                'behavior' => $this->behavior,
                Import::FIELD_NAME_VALIDATION_STRATEGY => $this->validationStrategy,
                Import::FIELD_NAME_ALLOWED_ERROR_COUNT => $this->allowedErrorCount,
                Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR => $this->multipleValueSeparator,
            ]);
            $this->importModel = $importModel;
        }

        return $this->importModel;
    }

    /**
     * Validate and import the given rows through the native import framework.
     *
     * @param array<int, array<string, mixed>> $data
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function processImport(array $data): void
    {
        $import = $this->getImportModel();

        $source = $this->arrayAdapterFactory->create([
            'data' => $data,
            'multipleValueSeparator' => $this->multipleValueSeparator,
        ]);

        $import->validateSource($source);
        $import->importSource();
        $import->invalidateIndex();

        $this->errorMessages = [];
        foreach ($import->getErrorAggregator()->getAllErrors() as $error) {
            $this->errorMessages[] = $error->getErrorMessage();
        }
    }

    /**
     * Human-readable trace of what the importer did, mirroring the admin log.
     *
     * @return string
     */
    public function getLogTrace(): string
    {
        return $this->getImportModel()->getFormatedLogTrace();
    }

    /**
     * Error messages collected during the last processImport() call.
     *
     * @return string
     */
    public function getErrorMessages(): string
    {
        return implode(PHP_EOL, $this->errorMessages);
    }
}
