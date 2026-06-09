<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Component\CatalogPriceRules;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\ComponentProcessorInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterfaceFactory;
use Magento\CatalogRule\Model\Rule;
use Magento\CatalogRule\Model\Rule\Job;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class CatalogPriceRulesProcessor implements ComponentProcessorInterface
{
    /**
     * @var array
     */
    private $rules = [];

    /**
     * @var array
     */
    private $config = [];

    /**
     * @var RuleInterfaceFactory
     */
    private $ruleFactory;

    /**
     * @var CatalogRuleRepositoryInterface
     */
    private $catalogRuleRepo;

    /**
     * @var Job
     */
    private $ruleJob;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ReconciliationGate
     */
    private $gate;

    /**
     * @var ComponentMode
     */
    private $mode = ComponentMode::Maintain;

    /**
     * CatalogPriceRules constructor.
     *
     * @param LoggerInterface $logger
     * @param RuleInterfaceFactory $ruleFactory
     * @param CatalogRuleRepositoryInterface $catalogRuleRepo
     * @param Job $ruleJob
     * @param ReconciliationGate $gate
     */
    public function __construct(
        LoggerInterface $logger,
        RuleInterfaceFactory $ruleFactory,
        CatalogRuleRepositoryInterface $catalogRuleRepo,
        Job $ruleJob,
        ReconciliationGate $gate
    ) {
        $this->logger = $logger;
        $this->ruleFactory = $ruleFactory;
        $this->catalogRuleRepo = $catalogRuleRepo;
        $this->ruleJob = $ruleJob;
        $this->gate = $gate;
    }

    /**
     * @param ComponentMode $mode
     *
     * @return $this
     */
    public function setMode(ComponentMode $mode)
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * @param array $data
     *
     * @return $this
     */
    public function setData(array $data)
    {
        $this->rules = $data;

        return $this;
    }

    /**
     * @param array $config
     *
     * @return $this
     */
    public function setConfig(array $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Configure rules
     *
     * @return void
     */
    public function process()
    {
        $rulesCount = count($this->rules);
        $ite = 1;
        $this->logger->logInfo('Initializing configuration of Catalog Price Rules.');

        foreach ($this->rules as $ruleId => $ruleData) {
            $this->logger->logInfo("Processing {$ruleId} [{$ite}/{$rulesCount}]...", 1);

            // Check the existing rule by the rule name
            /** @var \Magento\CatalogRule\Model\ResourceModel\Rule\Collection $ruleCollection */
            $ruleCollection = $this->ruleFactory->create()->getCollection()
                ->addFieldToFilter('name', $ruleData['name']);

            // Check to see we only have one of those rules by name
            if ($ruleCollection->getSize() > 1) {
                // Log an error and skip if there are more than 1 rules
                $this->logger->logError(sprintf(
                    'There appears to be more than 1 rule in Magento with the name "%s."',
                    $ruleData['name']
                ), 1);

                continue;
            }

            // Get the first rule
            $rule = $ruleCollection->getFirstItem();

            $version = $ruleData['version'] ?? null;
            $request = new ReconciliationRequest(
                'catalog_price_rules',
                (string) $ruleData['name'],
                $this->mode,
                $rule->getId() !== null,
                $version ? (int) $version : null
            );

            if ($this->gate->decide($request)->isSkip()) {
                $this->logger->logComment(
                    sprintf('Rule "%s" exists, skipped (create mode)', $ruleData['name']),
                    1
                );
                $ite++;
                continue;
            }

            // If the rule does not exist, create a new one
            if ($rule->getId() === null) {
                $rule = $this->ruleFactory->create();
            }

            unset($ruleData['version']);

            /** @var Rule $rule */
            $this->fillRuleWithData($rule, $ruleData);

            try {
                // Save the rule
                $this->catalogRuleRepo->save($rule);
                $this->gate->commitVersion($request, false);
            } catch (\Exception $ex) {
                $this->logger->logError($ex->getMessage());
            }

            $ite++;
        }

        if ($this->isApplyAll()) {
            $this->logger->logInfo('- Applying all rules...');
            $this->ruleJob->applyAll();
        }

        $this->logger->logInfo('Catalog price rules configuration completed.');
    }

    /**
     * @param Rule $rule
     * @param array $ruleData
     *
     * @return void
     */
    private function fillRuleWithData(Rule $rule, array $ruleData)
    {
        // Loop through each key value
        foreach ($ruleData as $key => $value) {
            // Check if they're the same as what is on the database
            // If so, skip to the next key value pair
            if ($rule->getData($key) == $value) {
                if (!is_array($value)) {
                    $this->logger->logComment(sprintf('%s = %s', $key, $value), 2);
                }
                continue;
            }

            // otherwise, Set the data
            $rule->setData($key, $value);

            // Log it
            if (!is_array($value)) {
                $this->logger->logInfo(sprintf('%s = %s', $key, $value), 2);
            }
        }

        // Load the rule data into the rule
        $rule->loadPost($rule->getData());
    }

    /**
     * Checks if should apply all rules
     *
     * @return bool
     */
    private function isApplyAll()
    {
        return isset($this->config['apply_all']) && true === $this->config['apply_all'];
    }
}
