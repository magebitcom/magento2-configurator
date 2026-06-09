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
use Magebit\Configurator\Component\CatalogPriceRules\CatalogPriceRulesProcessor;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;

/**
 * Manages Catalog Price Rules by delegating to the CatalogPriceRulesProcessor.
 */
class CatalogPriceRules implements ComponentInterface
{
    private const ALIAS = 'catalog_price_rules';
    private const DESCRIPTION = 'Component to manage Catalog Price Rules';

    public function __construct(
        private readonly CatalogPriceRulesProcessor $processor,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * Process the data and populate the Magento database.
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['rules']) || !is_array($data['rules'])) {
            $result->addError('No "rules" node found in the source data.');
            return $result;
        }

        $rules = $data['rules'];
        $config = $data['config'] ?? [];

        $ruleCount = count($rules);

        if ($context->isDryRun()) {
            $this->log->logInfo(
                sprintf('[dry-run] Would process %d Catalog Price Rule(s)', $ruleCount)
            );
            $result->recordCreated($ruleCount);

            return $result;
        }

        $this->processor->setData($rules)
            ->setConfig($config)
            ->setMode($context->getMode())
            ->process();

        $result->recordCreated($ruleCount);

        return $result;
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
