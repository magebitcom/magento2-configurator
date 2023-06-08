<?php

namespace CtiDigital\Configurator\Component;

use CtiDigital\Configurator\Api\ComponentInterface;
use CtiDigital\Configurator\Api\LoggerInterface;
use CtiDigital\Configurator\Exception\ComponentException;
use CtiDigital\Configurator\Model\Processor;
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

    protected string $alias = 'config';
    protected string $name = 'Configuration';
    protected string $description = 'Component to set the store/system configuration values';

    /**
     * @var ConfigResource
     */
    protected ConfigResource $configResource;

    /**
     * @var ScopeConfig
     */
    protected ScopeConfig $scopeConfig;

    /**
     * @var CollectionFactory
     */
    protected CollectionFactory $collectionFactory;

    /**
     * @var EncryptorInterface
     */
    protected EncryptorInterface $encryptor;

    /**
     * @var WebsiteFactory
     */
    protected WebsiteFactory $websiteFactory;

    /**
     * @var StoreFactory
     */
    protected StoreFactory $storeFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $log;

    /**
     * @var ScopeConfig\Initial
     */
    private ScopeConfig\Initial $initialConfig;

    /**
     * @var ConfigCollectionFactory
     */
    private ConfigCollectionFactory $configValueFactory;

    /**
     * Config constructor.
     * @param ConfigResource $configResource
     * @param ScopeConfig $scopeConfig
     * @param ScopeConfig\Initial $initialConfig
     * @param CollectionFactory $collectionFactory
     * @param EncryptorInterface $encryptor
     * @param WebsiteFactory $websiteFactory
     * @param StoreFactory $storeFactory
     * @param LoggerInterface $log
     */
    public function __construct(
        ConfigResource $configResource,
        ScopeConfig $scopeConfig,
        ScopeConfig\Initial $initialConfig,
        CollectionFactory $collectionFactory,
        EncryptorInterface $encryptor,
        WebsiteFactory $websiteFactory,
        StoreFactory $storeFactory,
        LoggerInterface $log,
        ConfigCollectionFactory $configValueFactory
    ) {
        $this->configValueFactory = $configValueFactory;
        $this->configResource = $configResource;
        $this->scopeConfig = $scopeConfig;
        $this->initialConfig = $initialConfig;
        $this->collectionFactory = $collectionFactory;
        $this->encryptor = $encryptor;
        $this->websiteFactory = $websiteFactory;
        $this->storeFactory = $storeFactory;
        $this->log = $log;
    }

    /**
     * @param null $data
     * @param string $mode
     * @SuppressWarnings(PHPMD)
     * @throws LocalizedException
     */
    public function execute($data = null, string $mode = Processor::MODE_MAINTAIN): void //phpcs:ignore Generic.Metrics.NestingLevel
    {
        try {
            $validScopes = ['global', 'websites', 'stores'];
            foreach ($data as $scope => $configurations) {
                if (!in_array($scope, $validScopes)) {
                    throw new ComponentException(sprintf("This is not a valid scope '%s' in your config.", $scope));
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
                            $mode
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
                                $code,
                                $encryption,
                                $mode
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
                                $code,
                                $encryption,
                                $mode
                            );
                        }
                    }
                }
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * @param array $configuration
     * @param int $encryption
     * @return int
     */
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
     * Set global store config
     *
     * @param string $path
     * @param string|null $value
     * @param int $encrypted
     * @param string $mode
     * @return void
     */
    private function setGlobalConfig(string $path, ?string $value = null, int $encrypted = 0, string $mode = Processor::MODE_MAINTAIN): void
    {
        try {
            // Check existing value, skip if the same
            $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $existingValue = $this->getSetConfigValue($path, $scope, 0);

            if (($existingValue !== false && $value == $existingValue) || ($existingValue && $mode == Processor::MODE_CREATE)) {
                $this->log->logComment(sprintf("Global Config Already Has Value: %s = %s", $path, $existingValue));
                return;
            }

            if ($encrypted) {
                $value = $this->encrypt($value);
            }

            // Save the config
            $this->configResource->saveConfig($path, $value, $scope, 0);
            $this->log->logInfo(sprintf("Global Config: %s = %s", $path, $value));
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Set config for website
     *
     * @param string $path
     * @param string|null $value
     * @param string $code
     * @param int $encrypted
     * @param string $mode
     * @return void
     */
    private function setWebsiteConfig(string $path, ?string $value, string $code, int $encrypted = 0, string $mode = Processor::MODE_MAINTAIN): void
    {
        try {
            $logNest = 1;
            $scope = 'websites';

            // Prepare Website ID;
            $website = $this->websiteFactory->create();
            $website->load($code, 'code');
            if (!$website->getId()) {
                throw new ComponentException(sprintf("There is no website with the code '%s'", $code));
            }

            // Check existing value, skip if the same
            $existingValue = $this->getSetConfigValue($path, $scope, $website->getId());
            if (($existingValue !== false && $value == $existingValue) || ($existingValue && $mode == Processor::MODE_CREATE)) {
                $this->log->logComment(sprintf("Website '%s' Config Already: %s = %s", $code, $path, $existingValue), $logNest);
                return;
            }

            if ($encrypted) {
                $value = $this->encrypt($value);
            }

            // Save the config
            $this->configResource->saveConfig($path, $value, $scope, $website->getId());
            $this->log->logInfo(sprintf("Website '%s' Config: %s = %s", $code, $path, $value), $logNest);
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Convert paths or values before they're processed
     *
     * @param array $configuration
     *
     * @return array
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
     * Set config for store view
     *
     * @param string $path
     * @param string|null $value
     * @param string $code
     * @param int $encrypted
     * @param string $mode
     * @return void
     * @throws LocalizedException
     */
    private function setStoreConfig(string $path, ?string $value, string $code, int $encrypted = 0, string $mode = Processor::MODE_MAINTAIN): void
    {
        try {
            $logNest = 2;
            $scope = 'stores';

            $storeView = $this->storeFactory->create();
            $storeView->load($code, 'code');
            if (!$storeView->getId()) {
                throw new ComponentException(sprintf("There is no store view with the code '%s'", $code));
            }

            // Check existing value, skip if the same
            $existingValue = $this->getSetConfigValue($path, $scope, $storeView->getId());
            if (($existingValue !== false && $value == $existingValue) || ($existingValue && $mode == Processor::MODE_CREATE)) {
                $this->log->logComment(sprintf("Store '%s' Config Already: %s = %s", $code, $path, $existingValue), $logNest);
                return;
            }

            if ($encrypted) {
                $value = $this->encrypt($value);
            }

            $this->configResource->saveConfig($path, $value, $scope, $storeView->getId());
            $this->log->logInfo(sprintf("Store '%s' Config: %s = %s", $code, $path, $value), $logNest);
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Checks if the config path is setting the theme by its path so we can get the ID
     *
     * @param $path
     * @param $value
     *
     * @return bool
     */
    public function isConfigTheme($path, $value): bool
    {
        if ($path === self::PATH_THEME_ID && is_int($value) === false) {
            return true;
        }
        return false;
    }

    /**
     * Get the theme ID by the path
     *
     * @param $themePath
     *
     * @return int
     */
    public function getThemeIdByPath($themePath): int
    {
        $themeCollection = $this->collectionFactory->create();
        $theme = $themeCollection->getThemeByFullPath($themePath);
        return $theme->getThemeId();
    }

    /**
     * Get Already set value in DB for the config
     *
     * @param string $path
     * @param string $scope
     * @param int $scopeId
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
     * @param $value
     * @return string
     */
    private function encrypt($value): string
    {
        return $this->encryptor->encrypt($value);
    }

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }
}
