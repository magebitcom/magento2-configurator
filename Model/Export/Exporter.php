<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model\Export;

use Magebit\Configurator\Api\ComponentListInterface;
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reverses configurator components: reads the current DB state via each
 * exportable component and writes it back into that component's source files,
 * so admin-made changes can be captured into version control.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class Exporter
{
    private const MASTER_PATH = 'app/etc/master.yaml';
    private const YAML_INLINE_DEPTH = 6;
    private const YAML_INDENT = 2;

    public function __construct(
        private readonly ComponentListInterface $componentList,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * @param string[] $aliases Component aliases to export; empty = all exportable in master.yaml.
     * @return array{written: string[], skipped: string[]} Files written and aliases skipped.
     */
    public function export(array $aliases, bool $full, ?string $filter, ?string $output, bool $dryRun): array
    {
        $master = $this->readMaster();
        $targets = $aliases !== [] ? $aliases : array_keys($master);

        $written = [];
        $skipped = [];

        foreach ($targets as $alias) {
            $component = $this->componentList->getComponent($alias);
            if (!$component instanceof ExportableComponentInterface) {
                $this->log->logComment(sprintf("Component '%s' is not exportable; skipping.", $alias));
                $skipped[] = $alias;
                continue;
            }

            if (!isset($master[$alias]['sources']) || $master[$alias]['sources'] === []) {
                $this->log->logError(sprintf("No sources defined for '%s' in master.yaml; skipping.", $alias));
                $skipped[] = $alias;
                continue;
            }

            $sources = (array) $master[$alias]['sources'];

            if ($full) {
                // One pass: write the full export to an explicit target or the first source.
                $target = $output ?? $this->resolvePath((string) $sources[0]);
                $data = $component->export(new ExportContext([], true, $filter));
                $this->writeFile($target, $data, $dryRun);
                $written[] = $target;
                continue;
            }

            // Refresh mode: rewrite each source file in place with current DB values.
            foreach ($sources as $source) {
                $path = $this->resolvePath((string) $source);
                $existing = $this->parseFile($path);
                $data = $component->export(new ExportContext($existing, false, $filter));
                $this->writeFile($path, $data, $dryRun);
                $written[] = $path;
            }
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>
     * @throws ComponentException
     */
    private function readMaster(): array
    {
        $path = BP . '/' . self::MASTER_PATH;
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!file_exists($path)) {
            throw new ComponentException(sprintf('Master YAML does not exist at %s', $path));
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parsed = Yaml::parse((string) file_get_contents($path));

        return is_array($parsed) ? $parsed : [];
    }

    private function resolvePath(string $source): string
    {
        return BP . '/' . ltrim($source, '/');
    }

    /**
     * @return array
     */
    private function parseFile(string $path): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!file_exists($path)) {
            return [];
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parsed = Yaml::parse((string) file_get_contents($path));

        return is_array($parsed) ? $parsed : [];
    }

    private function writeFile(string $path, array $data, bool $dryRun): void
    {
        $yaml = Yaml::dump($data, self::YAML_INLINE_DEPTH, self::YAML_INDENT, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would write %s (%d bytes)', $path, strlen($yaml)));
            return;
        }

        $dir = dirname($path);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!is_dir($dir)) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            mkdir($dir, 0755, true);
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        file_put_contents($path, $yaml);
        $this->log->logInfo(sprintf('Wrote %s', $path));
    }
}
