<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Console\Command;

use Magebit\Configurator\Exception\ConfiguratorAdapterException;
use Magebit\Configurator\Model\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    /**
     * @var Processor
     */
    private $processor;

    public function __construct(
        Processor $processor
    ) {
        parent::__construct();
        $this->processor = $processor;
    }

    protected function configure()
    {
        $environmentOption = new InputOption(
            'env',
            'e',
            InputOption::VALUE_REQUIRED,
            'Specify environment configuration'
        );

        $componentOption = new InputOption(
            'component',
            'c',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'The component to be run',
            []
        );

        $ignoreMissingFiles = new InputOption(
            'ignore-missing-files',
            'i',
            InputOption::VALUE_OPTIONAL,
            'Configurator continues if a source file is missing',
            false
        );

        $dryRun = new InputOption(
            'dry-run',
            'd',
            InputOption::VALUE_NONE,
            'Report what would change without persisting anything (components honour this as they are migrated)'
        );

        $this
            ->setName('configurator:run')
            ->setDescription('Run configurator components')
            ->setDefinition(
                new InputDefinition([
                    $environmentOption,
                    $componentOption,
                    $ignoreMissingFiles,
                    $dryRun
                ])
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @SuppressWarnings(PHPMD)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL) {
                $output->writeln('<comment>Starting Configurator</comment>');
            }

            $environment = $input->getOption('env');
            $components = $input->getOption('component');
            if ($input->getOption('ignore-missing-files') !== false) {
                $this->processor->setIgnoreMissingFiles(true);
            }

            if ($input->getOption('dry-run')) {
                $this->processor->setDryRun(true);
                $output->writeln('<comment>Dry run: no changes will be persisted (where supported)</comment>');
            }

            $logLevel = OutputInterface::VERBOSITY_NORMAL;
            $verbose = $input->getOption('verbose');

            if ($environment == null) {
                throw new ConfiguratorAdapterException('Please specify an environment using --env="<environment>"');
            }

            if ($verbose) {
                $logLevel = OutputInterface::VERBOSITY_VERBOSE;
            }

            $this->processor->setEnvironment($environment);

            foreach ($components as $component) {
                $this->processor->addComponent($component);
            }

            $this->processor->getLogger()->setLogLevel($logLevel);
            $this->processor->run();

            $result = $this->processor->getRunResult();
            $output->writeln(sprintf('<info>Configurator finished: %s</info>', $result->summary()));

            if (!$result->isSuccessful()) {
                $output->writeln(sprintf(
                    '<error>%d error(s) occurred during the run; see the log above.</error>',
                    count($result->getErrors())
                ));
                return Command::FAILURE;
            }

            if ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL) {
                $output->writeln('<comment>Finished Configurator</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
