<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Console\Command;

use Magebit\Configurator\Model\Export\Exporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reverse of configurator:run — read the current database state via exportable
 * components and write it back into the configurator source files, so changes
 * made in the admin can be captured into version control.
 */
class SyncFromDbCommand extends Command
{
    public function __construct(
        private readonly Exporter $exporter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('configurator:sync-from-db')
            ->setDescription('Sync current DB values back into the configurator source files')
            ->addOption(
                'component',
                'c',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Component alias to export (repeatable); default = all exportable components',
                []
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Full export: write everything in scope, not just the entries already tracked in the source files'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED,
                'Optional filter passed to the component (e.g. a config path prefix like "web/")'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Target file for a full export (defaults to the component\'s first source)'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Report what would be written without touching any files'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('<comment>Dry run: no files will be written</comment>');
        }

        try {
            $result = $this->exporter->export(
                (array) $input->getOption('component'),
                (bool) $input->getOption('all'),
                $input->getOption('path'),
                $input->getOption('output'),
                $dryRun
            );
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        foreach ($result['written'] as $file) {
            $output->writeln(sprintf('<info>%s %s</info>', $dryRun ? 'would write' : 'wrote', $file));
        }
        foreach ($result['skipped'] as $alias) {
            $output->writeln(sprintf('<comment>skipped %s (not exportable / no sources)</comment>', $alias));
        }

        $output->writeln(sprintf(
            '<info>Sync finished: %d file(s) %s, %d component(s) skipped.</info>',
            count($result['written']),
            $dryRun ? 'to write' : 'written',
            count($result['skipped'])
        ));

        return Command::SUCCESS;
    }
}
