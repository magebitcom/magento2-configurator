<?php

declare(strict_types=1);

namespace CtiDigital\Configurator\Api;

use CtiDigital\Configurator\Api\Data\ConfigInterface;

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