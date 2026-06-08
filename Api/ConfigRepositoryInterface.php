<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Api;

use Magebit\Configurator\Api\Data\ConfigInterface;

interface ConfigRepositoryInterface
{
    /**
     * Get config
     *
     * @param string $name
     * @return ConfigInterface|null
     */
    public function getConfig(string $name): ?ConfigInterface;

    /**
     * Save config
     *
     * @param ConfigInterface $config
     * @return int
     */
    public function save(ConfigInterface $config): int;

    /**
     * Delete COnfig
     *
     * @param ConfigInterface $config
     * @return void
     */
    public function delete(ConfigInterface $config): void;
}