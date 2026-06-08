<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\ComponentListInterface;
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use \Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Manager;
/**
 * Class Processor - The overarching class that reads and processes the configurator files.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Processor
{
    public const MODE_MAINTAIN = 'maintain';
    public const MODE_CREATE = 'create';

    public const SOURCE_YAML = 'yaml';
    public const SOURCE_CSV = 'csv';
    public const SOURCE_JSON = 'json';

    /**
     * @var string
     */
    protected $environment;

    /**
     * @var []
     */
    protected $components = [];

    /**
     * @var ComponentListInterface
     */
    protected $componentList;

    /**
     * @var State
     */
    protected $state;

    /**
     * @var LoggerInterface
     */
    protected $log;

    /**
     * @var FullModuleList
     */
    protected $fullModuleList;

    /**
     * @var Dir
     */
    protected $dir;

    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var bool
     */
    protected $ignoreMissingFiles = false;

    /**
     * @var bool
     */
    protected $dryRun = false;

    /**
     * @var ComponentResult
     */
    protected $runResult;

    /**
     * Processor constructor.
     * @param ComponentListInterface $componentList
     * @param State $state
     * @param LoggerInterface $logging
     */
    public function __construct(
        ComponentListInterface $componentList,
        State $state,
        LoggerInterface $logging,
        FullModuleList $fullModuleList,
        Dir $dir,
        Manager $manager
    ) {
        $this->componentList = $componentList;
        $this->state = $state;
        $this->log = $logging;
        $this->fullModuleList = $fullModuleList;
        $this->dir = $dir;
        $this->manager = $manager;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->log;
    }

    /**
     * @param bool $setting
     * @return void
     */
    public function setIgnoreMissingFiles($setting): void
    {
        $this->ignoreMissingFiles = $setting;
    }

    /**
     * @return bool
     */
    public function isIgnoreMissingFiles(): bool
    {
        return $this->ignoreMissingFiles;
    }

    /**
     * @param bool $dryRun
     * @return void
     */
    public function setDryRun($dryRun): void
    {
        $this->dryRun = (bool) $dryRun;
    }

    /**
     * @return bool
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Aggregate outcome of the last run(); usable for reporting and exit codes.
     *
     * @return ComponentResult
     */
    public function getRunResult(): ComponentResult
    {
        if ($this->runResult === null) {
            $this->runResult = new ComponentResult();
        }
        return $this->runResult;
    }

    /**
     * @param string $componentName
     * @return Processor
     */
    public function addComponent($componentName): self
    {
        $this->components[$componentName] = $componentName;
        return $this;
    }

    /**
     * @return array
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    /**
     * @param string $environment
     * @return Processor
     */
    public function setEnvironment($environment): self
    {
        $this->environment = $environment;
        return $this;
    }

    /**
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Run the components individually
     */
    public function run(): void
    {
        $this->runResult = new ComponentResult();

        // If the components list is empty, then the user would want to run all components in the master.yaml
        if (empty($this->components)) {
            $this->runAllComponents();
            return;
        }

        $this->runIndividualComponents();
    }

    /**
     * @return void
     * @throws Exception
     */
    private function runIndividualComponents(): void
    {
        try {
            // Get the master yaml
            $master = $this->getMasterYaml();

            // Loop through the components
            foreach ($this->components as $componentAlias) {
                // Get the config for the component from the master yaml array
                if (!isset($master[$componentAlias])) {
                    throw new ComponentException(
                        sprintf("No master yaml definition with the alias '%s' found", $componentAlias)
                    );
                }

                $masterConfig = $master[$componentAlias];

                // Run that component
                $areaCode = ($componentAlias === 'pages') ? Area::AREA_FRONTEND : Area::AREA_ADMINHTML;
                $this->state->emulateAreaCode(
                    $areaCode,
                    [$this, 'runComponent'],
                    [$componentAlias, $masterConfig]
                );

            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    private function runAllComponents(): void
    {
        try {
            // Get the master yaml
            $master = $this->getMasterYaml();

            // Loop through components and run them individually in the master.yaml order
            foreach ($master as $componentAlias => $componentConfig) {
                if ($componentConfig['enabled'] === 0) {
                    continue;
                }
                // Run the component in question
                $areaCode = ($componentAlias === 'pages') ? Area::AREA_FRONTEND : Area::AREA_ADMINHTML;
                $this->state->emulateAreaCode(
                    $areaCode,
                    [$this, 'runComponent'],
                    [$componentAlias, $componentConfig]
                );

            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * @param $componentAlias
     * @param $componentConfig
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @throws Exception
     */
    public function runComponent($componentAlias, $componentConfig): void
    {
        $this->log->logComment("");
        $this->log->logComment(str_pad("----------------------", (22 + strlen((string) $componentAlias)), "-"));
        $this->log->logComment(sprintf("| Loading component %s |", $componentAlias));
        $this->log->logComment(str_pad("----------------------", (22 + strlen((string) $componentAlias)), "-"));

        /* @var ComponentInterface $component */
        $component = $this->componentList->getComponent($componentAlias);

        $sourceType = (isset($componentConfig['type']) === true) ? $componentConfig['type'] : null;

        $modeValue = $componentConfig['env'][$this->getEnvironment()]['mode'] ?? self::MODE_CREATE;
        $mode = ComponentMode::tryFrom((string) $modeValue) ?? ComponentMode::Create;

        if (isset($componentConfig['sources'])) {
            foreach ($componentConfig['sources'] as $source) {
                try {
                    $this->executeComponentSource($component, $componentAlias, $source, $sourceType, $mode);
                } catch (ComponentException $e) {
                    if ($this->isIgnoreMissingFiles() === true) {
                        $this->log->logInfo("Skipping file {$source} as it could not be found.");
                        continue;
                    }
                    throw $e;
                } catch (\Throwable $t) {
                    $this->recordComponentFailure($componentAlias, $source, $t);
                }
            }
        }

        // Check if there are environment specific nodes placed
        if (!isset($componentConfig['env'])) {
            // If not, continue to next component
            $this->log->logComment(
                sprintf("No environment node for '%s' component", $componentAlias)
            );
            return;
        }

        // Check if there is a node for this particular environment
        if (!isset($componentConfig['env'][$this->getEnvironment()])) {
            // If not, continue to next component
            $this->log->logComment(
                sprintf(
                    "No '%s' environment specific node for '%s' component",
                    $this->getEnvironment(),
                    $componentAlias
                )
            );
            return;
        }

        // Check if there are sources for the environment
        if (!isset($componentConfig['env'][$this->getEnvironment()]['sources'])) {
            // If not continue
            $this->log->logComment(
                sprintf(
                    "No '%s' environment specific sources for '%s' component",
                    $this->getEnvironment(),
                    $componentAlias
                )
            );
            return;
        }

        // If there are sources for the environment, process them
        foreach ((array) $componentConfig['env'][$this->getEnvironment()]['sources'] as $source) {
            try {
                $sourceType = (isset($componentConfig['type']) === true) ? $componentConfig['type'] : null;
                $this->executeComponentSource($component, $componentAlias, $source, $sourceType, $mode);
            } catch (ComponentException $e) {
                if ($this->isIgnoreMissingFiles() === true) {
                    $this->log->logInfo("Skipping file {$source} as it could not be found.");
                    continue;
                }
                throw $e;
            } catch (\Throwable $t) {
                $this->recordComponentFailure($componentAlias, $source, $t);
            }
        }
    }

    /**
     * Record an unexpected component failure so the run can continue and still
     * exit non-zero, instead of one broken component aborting everything.
     *
     * @param string $componentAlias
     * @param string $source
     * @param \Throwable $t
     * @return void
     */
    private function recordComponentFailure($componentAlias, $source, \Throwable $t): void
    {
        $message = sprintf(
            "[%s] %s failed on source '%s': %s (%s:%d)",
            $componentAlias,
            get_class($t),
            $source,
            $t->getMessage(),
            basename($t->getFile()),
            $t->getLine()
        );
        $this->log->logError($message);
        $this->getRunResult()->addError($message);
    }

    /**
     * Build the context for a single source, run the component, and fold its
     * result into the run-level total.
     *
     * @param ComponentInterface $component
     * @param string $componentAlias
     * @param string $source
     * @param string|null $sourceType
     * @param ComponentMode $mode
     * @return void
     */
    private function executeComponentSource(
        ComponentInterface $component,
        $componentAlias,
        $source,
        $sourceType,
        ComponentMode $mode
    ): void {
        $context = new ComponentContext(
            (string) $source,
            $mode,
            $this->getEnvironment(),
            $this->dryRun,
            fn (string $path): array => $this->parseData($path, $sourceType)
        );

        $result = $component->execute($context);
        $this->getRunResult()->merge($result);

        // Components log their own per-item errors; the result drives the run
        // summary and the command's exit code.
        $this->log->logComment(sprintf("Component '%s' source '%s': %s", $componentAlias, $source, $result->summary()));
    }

    /**
     * @return array
     */
    private function getMasterYaml(): array
    {
        // Read master yaml
        $masterPath = BP . '/app/etc/master.yaml';
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!file_exists($masterPath)) {
            throw new ComponentException("Master YAML does not exist. Please create one in $masterPath");
        }
        $this->log->logComment(sprintf("Found Master YAML"));
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $yamlContents = file_get_contents($masterPath);
        $yaml = new Parser();
        $master = $yaml->parse($yamlContents);

        $this->mergeAdditionalMasters($master);

        $additionalSources = $master['additional_sources'] ?? [];
        unset($master['additional_sources']);

        foreach ($additionalSources as $additionalSource) {
           $additionalPath = BP . '/' . $additionalSource;
            if (!file_exists($additionalPath)) {
                throw new ComponentException("Additional source $additionalSource YAML does not exist.");
            }
            $this->log->logComment(sprintf("Found $additionalSource YAML"));
            $yamlContents = file_get_contents($additionalPath);
            $yaml = new Parser();
            $additional = $yaml->parse($yamlContents);
            foreach($additional as $key => $value) {
                foreach($value['sources'] as &$source) {
                    if (str_starts_with($source, './configurator/')) {
                        $source = str_replace(BP . '/', '', dirname($additionalPath)) . substr($source, 1);
                    }
                }

                if (isset($master[$key])) {
                    $master[$key]['sources'] = array_merge($value['sources'], $master[$key]['sources']);
                } else {
                    $master[$key] = $value;
                }
            }
        }

        // Validate master yaml
        $this->validateMasterYaml($master);

        return $master;
    }

    /**
     * See if the component in master yaml exists
     *
     * @param $componentName
     * @return bool
     */
    private function isValidComponent($componentName): bool
    {
        if ($this->log->getLogLevel() > OutputInterface::VERBOSITY_NORMAL) {
            $this->log->logQuestion(sprintf("Does the %s component exist?", $componentName));
        }
        $component = $this->componentList->getComponent($componentName);

        if ($component instanceof ComponentInterface) {
            return true;
        }
        return false;
    }

    /**
     * Basic validation of master yaml requirements
     *
     * @param $master
     * @SuppressWarnings(PHPMD)
     */
    private function validateMasterYaml($master): void
    {
        try {
            foreach ($master as $componentAlias => $componentConfig) {
                // Check it has a enabled node
                if (!isset($componentConfig['enabled'])) {
                    throw new ComponentException(
                        sprintf('It appears %s does not have a "enabled" node. This is required.', $componentAlias)
                    );
                }
                // Check it has at least 1 data source
                $componentHasSource = false;

                if (isset($componentConfig['sources']) &&
                    is_array($componentConfig['sources']) &&
                    count($componentConfig['sources']) > 0 === true
                ) {
                    $componentHasSource = true;
                }

                if (isset($componentConfig['env']) === true) {
                    foreach ($componentConfig['env'] as $envData) {
                        if (isset($envData['sources']) &&
                            is_array($envData['sources']) &&
                            count($envData['sources']) > 0 === true
                        ) {
                            $componentHasSource = true;
                            break;
                        }
                    }
                }

                if ($componentHasSource === false) {
                    throw new ComponentException(
                        sprintf('It appears there are no data sources for the %s component.', $componentAlias)
                    );
                }

                // Check the component exist
                if (!$this->isValidComponent($componentAlias)) {
                    throw new ComponentException(
                        sprintf(
                            '%s not a valid component. Please verify using bin/magento component:list.',
                            $componentAlias
                        )
                    );
                }
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * @param $source
     * @param $sourceType
     * @return mixed
     * @throws Exception
     */
    private function parseData($source, $sourceType): mixed
    {
        if ($this->canParseAndProcess($source) === true) {
            $ext = ($sourceType !== null) ? $sourceType : $this->getExtension($source);

            if ($ext === self::SOURCE_YAML) {
                $sourceData = $this->getData($source);
                return $this->parseYamlData($sourceData);
            }
            if ($ext === self::SOURCE_CSV) {
                // Data is read directly from the source by parseCsvData()
                return $this->parseCsvData($source);
            }
            if ($ext === self::SOURCE_JSON) {
                $sourceData = $this->getData($source);
                return $this->parseJsonData($sourceData);
            }
        }
    }

    /**
     * This method is used to check whether the data from file or a third party
     * can be parsed and processed. (e.g. does a YAML file exist for it?)
     *
     * This will determine whether the component is enabled or disabled.
     *
     * @return bool
     */
    private function canParseAndProcess($source): bool
    {
        $path = BP . '/' . $source;
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if ($this->isSourceRemote($source) === false && !file_exists($path)) {
            throw new ComponentException(
                sprintf("Could not find file in path %s", $path)
            );
        }
        return true;
    }

    /**
     * @param $source
     * @return bool
     */
    public function isSourceRemote($source): bool
    {
        return (filter_var($source, FILTER_VALIDATE_URL) !== false) ? true : false;
    }

    /**
     * @param $source
     * @return string
     * @throws Exception
     */
    private function getExtension($source): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $extension = pathinfo((string) $source, PATHINFO_EXTENSION);

        // For remote files, use the mime type to determine the extension
        if ($this->isSourceRemote($source)) {
            $extension = $this->getRemoteContentExtension($source);
        }

        if (strtolower((string) $extension) === 'yaml') {
            return self::SOURCE_YAML;
        }
        if (strtolower((string) $extension) === 'csv') {
            return self::SOURCE_CSV;
        }
        if (strtolower((string) $extension) === 'json') {
            return self::SOURCE_JSON;
        }
        throw new ComponentException(sprintf('Source "%s" does not have a valid file extension.', $source));
    }

    /**
     * @param $source
     * @return string|bool|null
     * @throws Exception
     */
    private function getData($source): string|bool|null
    {
        return ($this->isSourceRemote($source) === true) ?
            $this->getRemoteData($source) :
            file_get_contents(BP . '/' . $source); // phpcs:ignore Magento2.Functions.DiscouragedFunction
    }

    /**
     * @param $source
     * @return array|bool|false|float|int|mixed|string|null
     * @throws Exception
     */
    private function getRemoteContentExtension($source): mixed
    {
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $streamContext = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        } catch (Exception $e) {
            return '';
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $headers = get_headers($source, 1, $streamContext);
        $contentType = array_key_exists('Content-Type', $headers) ? $headers['Content-Type'] : '';

        // Parse the 'extension' from the content type
        $matches = [];
        preg_match('%^text/([a-z]+)%', (string) $contentType, $matches);
        return (count($matches) == 2) ? $matches[1] : null;
    }

    /**
     * @param $source
     * @return string|bool
     * @throws Exception
     */
    public function getRemoteData($source): string|bool
    {
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $streamContext = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        } catch (Exception $e) {
            return '';
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $remoteFile = file_get_contents($source, false, $streamContext);
        return $remoteFile;
    }

    /**
     * @param $source
     * @return mixed
     */
    private function parseYamlData($source): mixed
    {
        return (new Yaml())->parse($source);
    }

    /**
     * @param $source
     * @return mixed
     */
    private function getFileHandle($source): mixed
    {
        // Get a handle to the source data, whether it's remote or local
        if ($this->isSourceRemote($source)) {
            try {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $streamContext = stream_context_create(['ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);

                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                return fopen($source, 'r', false, $streamContext);
            } catch (Exception $ex) {
                throw new ComponentException("Can't open CSV source for reading: {$ex->getMessage()}");
            }
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return fopen($source, 'r');
    }

    /**
     * @param $source
     * @return array
     * @throws Exception
     */
    private function parseCsvData($source): array
    {
        $handle = $this->getFileHandle($source);

        // Read the header row
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $headerRow = fgetcsv($handle);
        $csvData = [$headerRow];

        // Read all other rows and build up an array, with row headers as keys
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        while (($csvLine = fgetcsv($handle)) !== false) {
            $csvRow = [];

            foreach (array_keys($headerRow) as $key) {
                $csvRow[$key] = (array_key_exists($key, $csvLine) === true) ? $csvLine[$key] : '';
            }

            $csvData[] = $csvRow;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        fclose($handle);
        return $csvData;
    }

    /**
     * @param $source
     * @return array|bool|float|int|mixed|string|null
     */
    private function parseJsonData($source): mixed
    {
        return json_decode((string) $source);
    }

    /**
     * @param $master
     * @return void
     */
    protected function mergeAdditionalMasters(&$master): void
    {
        foreach($this->fullModuleList->getAll() as $module) {
            $moduleName = $module['name'];
            if (!$this->manager->isEnabled($moduleName)) {
                continue;
            }
            $modulePath = $this->dir->getDir($moduleName, Dir::MODULE_ETC_DIR);
            if (!file_exists($modulePath . '/master.yaml')) {
                continue;
            }
            $this->log->logInfo(sprintf("Found %s master.yaml", $moduleName));
            $moduleConfig = $this->parseYamlData(file_get_contents($modulePath . '/master.yaml'));
            $master = array_merge_recursive($moduleConfig, $master);
        }
    }
}
