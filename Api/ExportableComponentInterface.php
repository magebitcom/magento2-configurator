<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Api;

use Magebit\Configurator\Model\Export\ExportContext;

/**
 * Implemented by components that can reverse — read their current database state
 * and produce data, in their own source format, for writing back to a
 * configurator source file (`configurator:sync-from-db`).
 *
 * Optional: components that cannot meaningfully be reversed (bulk imports, raw
 * SQL, media downloads, …) simply do not implement it.
 */
interface ExportableComponentInterface
{
    /**
     * Build source-format data reflecting the current DB state. The returned
     * array is dumped to the component's source file by the exporter.
     *
     * @param ExportContext $context
     * @return array
     */
    public function export(ExportContext $context): array;
}
