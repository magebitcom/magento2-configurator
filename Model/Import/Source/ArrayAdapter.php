<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model\Import\Source;

use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;

/**
 * Import source backed by an in-memory array of associative rows.
 *
 * Magento's import framework consumes an {@see AbstractSource}; the bundled
 * adapters only read CSV files from disk. This adapter lets a component feed
 * rows it has already built in PHP straight into the importer without writing
 * a temporary CSV file, which is the one capability the (GPL-licensed)
 * firegento/fastsimpleimport package used to provide for us.
 *
 * Column names are taken from the keys of the first row; every subsequent row
 * is normalised to that column order so the parent's array_combine() in
 * current() always lines up.
 */
class ArrayAdapter extends AbstractSource
{
    /** @var array<int, array<int, mixed>> Rows as positional value lists in column order. */
    private array $rows = [];

    private int $position = 0;

    private string $multipleValueSeparator;

    /**
     * @param array<int, array<string, mixed>> $data
     */
    public function __construct(
        array $data,
        string $multipleValueSeparator = Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR
    ) {
        $this->multipleValueSeparator = $multipleValueSeparator;

        $data = array_values($data);
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot build an import source from an empty data set.');
        }

        $colNames = array_keys($data[0]);
        foreach ($data as $row) {
            $values = [];
            foreach ($colNames as $col) {
                $values[] = $row[$col] ?? null;
            }
            $this->rows[] = $values;
        }

        parent::__construct($colNames);
    }

    /**
     * Separator used between several values packed into a single cell.
     *
     * @return string
     */
    public function getMultipleValueSeparator(): string
    {
        return $this->multipleValueSeparator;
    }

    /**
     * @inheritDoc
     * @return array<int, mixed>|false
     */
    protected function _getNextRow()
    {
        if (!array_key_exists($this->position, $this->rows)) {
            return false;
        }

        return $this->rows[$this->position++];
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        $this->position = 0;
        parent::rewind();
    }
}
