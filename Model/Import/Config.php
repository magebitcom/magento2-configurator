<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model\Import;

/**
 * Resolves the media subdirectory that product import images are read from
 * and written to.
 *
 * Replaces FireGento\FastSimpleImport\Model\Config::getImportFileDir(). The
 * import framework looks for image files under <media>/import by default, which
 * is what this returns; it is exposed as a method so it can be overridden via
 * a di.xml preference if a project needs a different location.
 */
class Config
{
    /**
     * Default media subdirectory products are imported from.
     */
    public const DEFAULT_IMPORT_FILE_DIR = 'import';

    /**
     * @return string
     */
    public function getImportFileDir(): string
    {
        return self::DEFAULT_IMPORT_FILE_DIR;
    }
}
