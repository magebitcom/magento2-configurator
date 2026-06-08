<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model;

use Magebit\Configurator\Api\Data\ConfigInterfaceFactory;
use Magebit\Configurator\Api\Data\ConfigInterface;
use Magebit\Configurator\Api\VersionManagementInterface;

class VersionManagement implements VersionManagementInterface
{
    private const DEFAULT_VERSION = 0;

    public const CONFIG_PREFIX = 'version_';

    /**
     * @param ConfigRepository $configRepository
     * @param ConfigInterfaceFactory $configInterfaceFactory
     */
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ConfigInterfaceFactory $configInterfaceFactory
    ) {
    }

    /**
     * @return int
     */
    public function getCurrentVersion(string $id): int
    {
        try {
            $config = $this->configRepository->getConfig(self::CONFIG_PREFIX . $id);

            if (!$config->getId()) {
                /** @var ConfigInterface $config */
                $config = $this->configInterfaceFactory->create();
                $config->setName(self::CONFIG_PREFIX . $id)
                    ->setValue((string) self::DEFAULT_VERSION);

                $this->configRepository->save($config);
            }

            return (int) $config->getValue();
        } catch (\Exception $exception) {
            return self::DEFAULT_VERSION;
        }
    }

    /**
     * @param int $version
     * @return void
     */
    public function setVersion(string $id, int $version): void
    {
        try {
            $config = $this->configRepository->getConfig(self::CONFIG_PREFIX . $id);

            if (!$config->getId()) {
                /** @var ConfigInterface $config */
                $config = $this->configInterfaceFactory->create();
                $config->setName(self::CONFIG_PREFIX . $id);
            }


            $config->setValue((string) $version);

            $this->configRepository->save($config);
        } catch (\Exception $exception) {
            // Do nothing
            // Most likely this is first time this is run and we
            // don't have the DB table yet.
        }
    }

    /**
     * @param int $version
     * @return bool
     */
    public function isNewVersion(string $id, int $version): bool
    {
        $currentVersion = $this->getCurrentVersion($id);

        return $currentVersion < $version;
    }
}
