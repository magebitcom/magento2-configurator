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
use Magebit\Configurator\Component\Processor\SqlSplitProcessor;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;

/**
 * Class Sql - Runs raw SQL queries - generally a fallback for when a configurator component is not available.
 */
class Sql implements ComponentInterface
{
    private const ALIAS = 'sql';
    private const DESCRIPTION = 'Component for an execution of custom queries';

    public function __construct(
        private readonly SqlSplitProcessor $processor,
        private readonly LoggerInterface $log
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['sql']) || !is_array($data['sql'])) {
            $result->addError('No "sql" node found in the source data.');
            return $result;
        }

        $this->log->logInfo('Beginning of custom queries configuration:');
        foreach ($data['sql'] as $name => $sqlFile) {
            $path = BP . '/' . $sqlFile;
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            if (false === file_exists($path)) {
                $this->log->logError("{$path} does not exist. Skipping.");
                continue;
            }

            if ($context->isDryRun()) {
                $this->log->logInfo(sprintf('[dry-run] Would execute SQL file "%s" (%s)', $name, $path));
                $result->recordCreated();
                continue;
            }

            $this->processor->process((string) $name, $path);
            $result->recordCreated();
        }

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
