<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Api;

interface VersionManagementInterface
{
    /**
     * @return int
     */
    public function getCurrentVersion(string $id): int;

    /**
     * @param int $version
     * @return void
     */
    public function setVersion(string $id, int $version): void;

    /**
     * @param int $version
     * @return bool
     */
    public function isNewVersion(string $id, int $version): bool;
}
