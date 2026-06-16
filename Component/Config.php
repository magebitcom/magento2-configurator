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
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
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

class Config implements ComponentInterface, ExportableComponentInterface
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
        private readonly ReconciliationGate $gate,
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
        $mode = $context->getMode();
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
                        // Explicit removal: `remove: true` deletes the stored value in
                        // either mode. Checked before validation so a removal entry
                        // need not carry a value.
                        if (!empty($configuration['remove'])) {
                            $this->removeConfig(
                                $configuration['path'],
                                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                                0,
                                $configuration['path'],
                                $dryRun,
                                $result
                            );
                            continue;
                        }

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
                            // Explicit removal: `remove: true` deletes the stored value.
                            if (!empty($configuration['remove'])) {
                                $scopeId = $this->resolveScopeId('websites', (string) $code);
                                if ($scopeId === null) {
                                    $this->log->logComment(
                                        sprintf("There is no website with the code '%s', nothing to remove", $code),
                                        1
                                    );
                                    $result->recordSkipped();
                                    continue;
                                }
                                $this->removeConfig(
                                    $configuration['path'],
                                    'websites',
                                    $scopeId,
                                    sprintf("website '%s' %s", $code, $configuration['path']),
                                    $dryRun,
                                    $result,
                                    1
                                );
                                continue;
                            }

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
                            // Explicit removal: `remove: true` deletes the stored value.
                            if (!empty($configuration['remove'])) {
                                $scopeId = $this->resolveScopeId('stores', (string) $code);
                                if ($scopeId === null) {
                                    $this->log->logComment(
                                        sprintf("There is no store view with the code '%s', nothing to remove", $code),
                                        2
                                    );
                                    $result->recordSkipped();
                                    continue;
                                }
                                $this->removeConfig(
                                    $configuration['path'],
                                    'stores',
                                    $scopeId,
                                    sprintf("store '%s' %s", $code, $configuration['path']),
                                    $dryRun,
                                    $result,
                                    2
                                );
                                continue;
                            }

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
        mixed $value = null,
        int $encrypted = 0,
        ComponentMode $mode = ComponentMode::Maintain,
        int|string|null $version = null,
        bool $dryRun = false,
        ?ComponentResult $result = null
    ): void {
        try {
            // Check existing value, skip if the same
            $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $existingValue = $this->getSetConfigValue($path, $scope, 0);

            // A stored "0"/""/null is still a real value: existence must test for the
            // row, not the value's truthiness. Using (bool) here made a falsy value look
            // unset, so the gate dropped create-mode protection and overwrote it.
            $exists = $existingValue !== false;
            $unchanged = $exists && $value == $existingValue;

            $request = new ReconciliationRequest(
                self::ALIAS,
                'global_' . $path,
                $mode,
                $exists,
                $version ? (int) $version : null,
                $unchanged
            );

            if ($this->gate->decide($request)->isSkip()) {
                $this->log->logComment(sprintf("Global Config Already Has Value: %s = %s", $path, $existingValue));
                // An unchanged value already matches the declared version; persist it so
                // a later manual edit isn't mistaken for a stale entity and overwritten.
                if ($unchanged) {
                    $this->gate->commitVersion($request, $dryRun);
                }
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
            $this->gate->commitVersion($request, $dryRun);
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
        mixed $value,
        string $code,
        int $encrypted = 0,
        ComponentMode $mode = ComponentMode::Maintain,
        int|string|null $version = null,
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

            // A stored "0"/""/null is still a real value: existence must test for the
            // row, not the value's truthiness. Using (bool) here made a falsy value look
            // unset, so the gate dropped create-mode protection and overwrote it.
            $exists = $existingValue !== false;
            $unchanged = $exists && $value == $existingValue;

            $request = new ReconciliationRequest(
                self::ALIAS,
                'website_' . $website->getId() . '_' . $path,
                $mode,
                $exists,
                $version ? (int) $version : null,
                $unchanged
            );

            if ($this->gate->decide($request)->isSkip()) {
                $this->log->logComment(
                    sprintf("Website '%s' Config Already: %s = %s", $code, $path, $existingValue),
                    $logNest
                );
                // An unchanged value already matches the declared version; persist it so
                // a later manual edit isn't mistaken for a stale entity and overwritten.
                if ($unchanged) {
                    $this->gate->commitVersion($request, $dryRun);
                }
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
            $this->gate->commitVersion($request, $dryRun);
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
        mixed $value,
        string $code,
        int $encrypted = 0,
        ComponentMode $mode = ComponentMode::Maintain,
        int|string|null $version = null,
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

            // A stored "0"/""/null is still a real value: existence must test for the
            // row, not the value's truthiness. Using (bool) here made a falsy value look
            // unset, so the gate dropped create-mode protection and overwrote it.
            $exists = $existingValue !== false;
            $unchanged = $exists && $value == $existingValue;

            $request = new ReconciliationRequest(
                self::ALIAS,
                'store_' . $storeView->getId() . '_' . $path,
                $mode,
                $exists,
                $version ? (int) $version : null,
                $unchanged
            );

            if ($this->gate->decide($request)->isSkip()) {
                $this->log->logComment(
                    sprintf("Store '%s' Config Already: %s = %s", $code, $path, $existingValue),
                    $logNest
                );
                // An unchanged value already matches the declared version; persist it so
                // a later manual edit isn't mistaken for a stale entity and overwritten.
                if ($unchanged) {
                    $this->gate->commitVersion($request, $dryRun);
                }
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
            $this->gate->commitVersion($request, $dryRun);
            $result?->recordCreated();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result?->addError($e->getMessage());
        }
    }

    /**
     * Delete a config value flagged with `remove: true`. Idempotent: if no value is
     * stored for the path+scope, records a skip rather than an error. Honors dry-run.
     * Deletes through the same resource the component uses to save config.
     *
     * @param string $path
     * @param string $scope
     * @param int $scopeId
     * @param string $label human-readable key used in log output
     * @param bool $dryRun
     * @param ComponentResult $result
     * @param int $logNest
     * @return void
     */
    private function removeConfig(
        string $path,
        string $scope,
        int $scopeId,
        string $label,
        bool $dryRun,
        ComponentResult $result,
        int $logNest = 0
    ): void {
        try {
            $existingValue = $this->getSetConfigValue($path, $scope, $scopeId);
            if ($existingValue === false) {
                $this->log->logComment(
                    sprintf("Config '%s' not present, nothing to remove", $label),
                    $logNest
                );
                $result->recordSkipped();
                return;
            }

            if ($dryRun) {
                $this->log->logInfo(sprintf('[dry-run] Would remove config %s', $label), $logNest);
            } else {
                $this->configResource->deleteConfig($path, $scope, $scopeId);
                $this->log->logInfo(sprintf('Removed config %s', $label), $logNest);
            }

            $result->recordRemoved();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
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

    /**
     * Export current config values into the source format. Refresh mode rewrites
     * only the paths already tracked in the source file; full mode dumps every
     * core_config_data row (optionally filtered by a path prefix). Encrypted
     * paths are never written out, so secrets don't land in version control.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->getFilter());
    }

    /**
     * @param array $existing
     * @param string|null $filter
     * @return array
     */
    private function refreshTracked(array $existing, ?string $filter): array
    {
        $out = [];

        if (isset($existing['global']) && is_array($existing['global'])) {
            $out['global'] = $this->refreshEntries(
                $existing['global'],
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                0,
                $filter
            );
        }

        foreach (['websites', 'stores'] as $scope) {
            if (!isset($existing[$scope]) || !is_array($existing[$scope])) {
                continue;
            }
            foreach ($existing[$scope] as $code => $entries) {
                $scopeId = $this->resolveScopeId($scope, (string) $code);
                if ($scopeId === null) {
                    $out[$scope][$code] = $entries;
                    continue;
                }
                $out[$scope][$code] = $this->refreshEntries((array) $entries, $scope, $scopeId, $filter);
            }
        }

        return $out;
    }

    /**
     * Refresh the `value` of each tracked entry from the DB, preserving any
     * other keys (version, encryption). Entries that don't match the filter, or
     * use an encrypted backend model, are kept untouched.
     *
     * @param array $entries
     * @return array
     */
    private function refreshEntries(array $entries, string $scope, int $scopeId, ?string $filter): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $path = $entry['path'] ?? null;
            if ($path === null
                || ($filter !== null && !str_starts_with((string) $path, $filter))
                || $this->isEncryptedPath((string) $path)
            ) {
                $result[] = $entry;
                continue;
            }

            $current = $this->getSetConfigValue((string) $path, $scope, $scopeId);
            if ($current !== false) {
                $entry['value'] = $current;
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $collection = $this->configValueFactory->create();
        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter('path', ['like' => $filter . '%']);
        }

        $out = [];
        foreach ($collection as $config) {
            $path = (string) $config->getPath();
            if ($this->isEncryptedPath($path)) {
                continue;
            }

            $scope = (string) $config->getScope();
            $scopeId = (int) $config->getScopeId();
            $entry = ['path' => $path, 'value' => $config->getValue()];

            if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT || $scopeId === 0) {
                $out['global'][] = $entry;
                continue;
            }

            $code = $scope === 'websites' ? $this->websiteCodeById($scopeId) : $this->storeCodeById($scopeId);
            if ($code !== null) {
                $out[$scope][$code][] = $entry;
            }
        }

        return $out;
    }

    private function resolveScopeId(string $scope, string $code): ?int
    {
        if ($scope === 'websites') {
            $website = $this->websiteFactory->create();
            $website->load($code, 'code');
            return $website->getId() ? (int) $website->getId() : null;
        }

        $store = $this->storeFactory->create();
        $store->load($code, 'code');
        return $store->getId() ? (int) $store->getId() : null;
    }

    private function websiteCodeById(int $scopeId): ?string
    {
        $website = $this->websiteFactory->create()->load($scopeId);
        return $website->getId() ? (string) $website->getCode() : null;
    }

    private function storeCodeById(int $scopeId): ?string
    {
        $store = $this->storeFactory->create()->load($scopeId);
        return $store->getId() ? (string) $store->getCode() : null;
    }

    /**
     * Whether a config path uses the encrypted backend model (so we never write
     * its decrypted value into a source file).
     */
    private function isEncryptedPath(string $path): bool
    {
        foreach ($this->initialConfig->getMetadata() as $metaPath => $processor) {
            if ($metaPath === $path
                && isset($processor['backendModel'])
                && $processor['backendModel'] === self::ENCRYPTED_MODEL
            ) {
                return true;
            }
        }

        return false;
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
