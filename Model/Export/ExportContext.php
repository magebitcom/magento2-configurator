<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model\Export;

/**
 * Inputs for an exportable component's export(): the parsed data already in the
 * source file being refreshed, whether the caller asked for a full export, and
 * an optional path/key filter.
 */
class ExportContext
{
    /**
     * @param array $existingData Parsed contents of the source file being refreshed (empty for a full export).
     * @param bool $full True when the caller wants everything in scope, not just tracked entries.
     * @param string|null $filter Optional prefix/key filter (component-specific; e.g. a config path prefix).
     */
    public function __construct(
        private readonly array $existingData = [],
        private readonly bool $full = false,
        private readonly ?string $filter = null
    ) {
    }

    public function getExistingData(): array
    {
        return $this->existingData;
    }

    public function isFullExport(): bool
    {
        return $this->full;
    }

    public function getFilter(): ?string
    {
        return $this->filter;
    }
}
