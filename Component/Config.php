<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Processor;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;
use Magento\Framework\App\Config as ScopeConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\WebsiteFactory;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory;

class Config implements ComponentInterface
{
    public const PATH_THEME_ID = 'design/theme/theme_id';
    public const ENCRYPTED_MODEL = Encrypted::class;

    private const ALIAS = 'config';
    private const DESCRIPTION = 'Component to set the store/system configuration values';

    public function __construct(
        protected readonly ConfigResource $configResource,
        protected readonly ScopeConfig $scopeConfig,
        protected readonly ScopeConfig\Initial $initialConfig,
        protected readonly CollectionFactory $collectionFactory,
        protected readonly EncryptorInterface $encryptor,
        protected readonly WebsiteFactory $websiteFactory,
        protected readonly StoreFactory $storeFactory,
        private readonly LoggerInterface $log,
        private readonly VersionManagementInterface $versionManagement,
        private readonly ConfigCollectionFactory $configValueFactory
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD)
     * @throws LocalizedException
     */
    public function execute(ComponentContext $context): ComponentResult //phpcs:ignore Generic.Metrics.NestingLevel
    {
        $result = new ComponentResult();
        $data = $context->getData();
        $mode = $context->getMode()->value;
        $dryRun = $context->isDryRun();

        if ($data === [] || !is_array($data)) {
            $result->addError('No configuration found in the source data.');
            return $result;
        }

        try {
            $validScopes = ['global', 'websites', 'stores'];
            foreach ($data as $scope => $configurations) {
                if (!in_array($scope, $validScopes)) {
                    throw new ComponentException(
                        (string) __("This is not a valid scope '%1' in your config.", $scope)
                    );
                }

                if ($scope == "global") {
                    foreach ($configurations as $configuration) {
                        // Handle encryption parameter
                        $encryption = 0;
                        if (isset($configuration['encryption']) && $configuration['encryption'] == 1) {
                            $encryption = 1;
                        }

                        $convertedConfiguration = $this->convert($configuration);
                        // Check if the path uses an encryption model. If yes, set encryption to true
                        $encryption = $this->determineEncryption($convertedConfiguration, $encryption);
                        $this->setGlobalConfig(
                            $convertedConfiguration['path'],
                            $convertedConfiguration['value'],
                            $encryption,
                            $mode,
                            $convertedConfiguration['version'] ?? null,
                            $dryRun,
                            $result
                        );
                    }
                }

                if ($scope == "websites") {
                    foreach ($configurations as $code => $websiteConfigurations) {
                        foreach ($websiteConfigurations as $configuration) {
                            // Handle encryption parameter
                            $encryption = 0;
                            if (isset($configuration['encryption']) && $configuration['encryption'] == 1) {
                                $encryption = 1;
                            }
                            $convertedConfiguration = $this->convert($configuration);
                            // Check if the path uses an encryption model. If yes, set encryption to true
                            $encryption = $this->determineEncryption($convertedConfiguration, $encryption);
                            $this->setWebsiteConfig(
                                $convertedConfiguration['path'],
                                $convertedConfiguration['value'],
                                (string) $code,
                                $encryption,
                                $mode,
                                $convertedConfiguration['version'] ?? null,
                                $dryRun,
                                $result
                            );
                        }
                    }
                }

                if ($scope == "stores") {
                    foreach ($configurations as $code => $storeConfigurations) {
                        foreach ($storeConfigurations as $configuration) {
                            // Handle encryption parameter
                            $encryption = 0;
                            if (isset($configuration['encryption']) && $configuration['encryption'] == 1) {
                                $encryption = 1;
                            }

                            $convertedConfiguration = $this->convert($configuration);
                            // Check if the path uses an encryption model. If yes, set encryption to true
                            $encryption = $this->determineEncryption($convertedConfiguration, $encryption);
                            $this->setStoreConfig(
                                $convertedConfiguration['path'],
                                $convertedConfiguration['value'],
                                (string) $code,
                                $encryption,
                                $mode,
                                $convertedConfiguration['version'] ?? null,
                                $dryRun,
                                $result
                            );
                        }
                    }
                }
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    private function determineEncryption(array $configuration, int $encryption): int
    {
        $metaData = $this->initialConfig->getMetadata();

        foreach ($metaData as $path => $processor) {
            if ($path == $configuration['path']) {
                if (isset($processor['backendModel']) && $processor['backendModel'] === self::ENCRYPTED_MODEL) {
                    $encryption = 1;
                }
            }
        }

        return $encryption;
    }

    /**
     * Set global store config.
     */
    private function setGlobalConfig(
        string $path,
        ?string $value = null,
        int $encrypted = 0,
        string $mode = Processor::MODE_MAINTAIN,
        ?string $version = null,
        bool $dryRun = false,
        ?ComponentResult $result = null
    ): void {
        try {
            // Check existing value, skip if the same
            $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $existingValue = $this->getSetConfigValue($path, $scope, 0);
            $versionId = self::ALIAS . '_global_' . $path;

            $isNewVersion = isset($version) && $this->versionManagement->isNewVersion($versionId, (int) $version);

            if (($existingValue !== false && $value == $existingValue) ||
                ($existingValue && $mode == Processor::MODE_CREATE && !$isNewVersion)
            ) {
                $this->log->logComment(sprintf("Global Config Already Has Value: %s = %s", $path, $existingValue));
                $result?->recordSkipped();
                return;
            }

            if ($encrypted) {
                $value = $this->encrypt($value);
            }

            if ($dryRun) {
                $this->log->logInfo(sprintf("[dry-run] Would set Global Config: %s = %s", $path, $value));
                $result?->recordCreated();
                return;
            }

            // Save the config
            $this->configResource->saveConfig($path, $value, $scope, 0);
            $this->log->logInfo(sprintf("Global Config: %s = %s", $path, $value));
            if ($version) {
                $this->versionManagement->setVersion($versionId, (int) $version);
            }
            $result?->recordCreated();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result?->addError($e->getMessage());
        }
    }

    /**
     * Set config for website.
     */
    private function setWebsiteConfig(
        string $path,
        ?string $value,
        string $code,
        int $encrypted = 0,
        string $mode = Processor::MODE_MAINTAIN,
        ?string $version = null,
        bool $dryRun = false,
        ?ComponentResult $result = null
    ): void {
        try {
            $logNest = 1;
            $scope = 'websites';

            // Prepare Website ID;
            $website = $this->websiteFactory->create();
            $website->load($code, 'code');
            if (!$website->getId()) {
                throw new ComponentException(
                    (string) __("There is no website with the code '%1'", $code)
                );
            }

            // Check existing value, skip if the same
            $existingValue = $this->getSetConfigValue($path, $scope, (int) $website->getId());
            $versionId = self::ALIAS . '_website_' . $website->getId() . '_' . $path;
            $isNewVersion = isset($version) && $this->versionManagement->isNewVersion($versionId, (int) $version);

            if (($existingValue !== false && $value == $existingValue) ||
                ($existingValue && $mode == Processor::MODE_CREATE && !$isNewVersion)
            ) {
                $this->log->logComment(
                    sprintf("Website '%s' Config Already: %s = %s", $code, $path, $existingValue),
                    $logNest
                );
                $result?->recordSkipped();
                return;
            }

            if ($encrypted) {
                $value = $this->encrypt($value);
            }

            if ($dryRun) {
                $this->log->logInfo(
                    sprintf("[dry-run] Would set Website '%s' Config: %s = %s", $code, $path, $value),
                    $logNest
                );
                $result?->recordCreated();
                return;
            }

            // Save the config
            $this->configResource->saveConfig($path, $value, $scope, (int) $website->getId());
            $this->log->logInfo(sprintf("Website '%s' Config: %s = %s", $code, $path, $value), $logNest);
            if ($version) {
                $this->versionManagement->setVersion($versionId, (int) $version);
            }
            $result?->recordCreated();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result?->addError($e->getMessage());
        }
    }

    /**
     * Convert paths or values before they're processed.
     */
    protected function convert(array $configuration): array
    {
        $convertedConfig = $configuration;
        if (isset($convertedConfig['path']) && isset($convertedConfig['value'])) {
            if ($this->isConfigTheme($convertedConfig['path'], $convertedConfig['value'])) {
                $convertedConfig['value'] = $this->getThemeIdByPath($convertedConfig['value']);
            }
        }
        return $convertedConfig;
    }

    /**
     * Set config for store view.
     *
     * @throws LocalizedException
     */
    private function setStoreConfig(
        string $path,
        ?string $value,
        string $code,
        int $encrypted = 0,
        string $mode = Processor::MODE_MAINTAIN,
        ?string $version = null,
        bool $dryRun = false,
        ?ComponentResult $result = null
    ): void {
        try {
            $logNest = 2;
            $scope = 'stores';

            $storeView = $this->storeFactory->create();
            $storeView->load($code, 'code');
            if (!$storeView->getId()) {
                throw new ComponentException(
                    (string) __("There is no store view with the code '%1'", $code)
                );
            }

            // Check existing value, skip if the same
            $existingValue = $this->getSetConfigValue($path, $scope, (int) $storeView->getId());
            $versionId = self::ALIAS . '_store_' . $storeView->getId() . '_' . $path;
            $isNewVersion = isset($version) && $this->versionManagement->isNewVersion($versionId, (int) $version);

            if (($existingValue !== false && $value == $existingValue) ||
                ($existingValue && $mode == Processor::MODE_CREATE && !$isNewVersion)) {
                $this->log->logComment(
                    sprintf("Store '%s' Config Already: %s = %s", $code, $path, $existingValue),
                    $logNest
                );
                $result?->recordSkipped();
                return;
            }

            if ($encrypted) {
                $value = $this->encrypt($value);
            }

            if ($dryRun) {
                $this->log->logInfo(
                    sprintf("[dry-run] Would set Store '%s' Config: %s = %s", $code, $path, $value),
                    $logNest
                );
                $result?->recordCreated();
                return;
            }

            $this->configResource->saveConfig($path, $value, $scope, (int) $storeView->getId());
            $this->log->logInfo(sprintf("Store '%s' Config: %s = %s", $code, $path, $value), $logNest);
            if ($version) {
                $this->versionManagement->setVersion($versionId, (int) $version);
            }
            $result?->recordCreated();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result?->addError($e->getMessage());
        }
    }

    /**
     * Checks if the config path is setting the theme by its path so we can get the ID.
     *
     * @param mixed $path
     * @param mixed $value
     */
    public function isConfigTheme($path, $value): bool
    {
        if ($path === self::PATH_THEME_ID && is_int($value) === false) {
            return true;
        }
        return false;
    }

    /**
     * Get the theme ID by the path.
     *
     * @param mixed $themePath
     */
    public function getThemeIdByPath($themePath): int
    {
        $themeCollection = $this->collectionFactory->create();
        $theme = $themeCollection->getThemeByFullPath($themePath);
        return (int) $theme->getThemeId();
    }

    /**
     * Get already set value in DB for the config.
     *
     * @return string|false|null
     */
    private function getSetConfigValue(string $path, string $scope, int $scopeId): string|false|null
    {
        $config = $this->configValueFactory->create()
            ->addFieldToFilter('scope', $scope)
            ->addFieldToFilter('scope_id', $scopeId)
            ->addFieldToFilter('path', ['eq' => $path])
            ->getFirstItem();

        if ($config->getId()) {
            return $config->getValue();
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function encrypt($value): string
    {
        return $this->encryptor->encrypt($value);
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
