<?php
/**
 * This file is part of the CtiDigital_Configurator package.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade CtiDigital_Configurator
 * to newer versions in the future.
 *
 * @copyright Copyright (c) 2023 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace CtiDigital\Configurator\Api;

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
