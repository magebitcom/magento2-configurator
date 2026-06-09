<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model\Export;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\ComponentListInterface;
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Export\Exporter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * An exportable component for use in these tests: it returns whatever data it
 * was primed with, and records the ExportContext it last received so the test
 * can assert on full vs refresh behaviour.
 */
interface ExportableComponentStub extends ComponentInterface, ExportableComponentInterface
{
}

class ExporterTest extends TestCase
{
    private ComponentListInterface&MockObject $componentList;
    private LoggerInterface&MockObject $log;
    private Exporter $exporter;

    /** @var string base path the exporter resolves files against (the BP constant) */
    private string $basePath;

    /** @var string[] absolute paths created for a test, cleaned up in tearDown */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $this->componentList = $this->createMock(ComponentListInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);

        // The exporter reads master.yaml and writes sources relative to BP. Pin BP
        // to a unique real temp directory so the filesystem behaviour is deterministic.
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir() . '/configurator-export-' . uniqid());
        }
        $this->basePath = BP;
        if (!is_dir($this->basePath . '/app/etc')) {
            mkdir($this->basePath . '/app/etc', 0777, true);
        }

        $this->exporter = new Exporter($this->componentList, $this->log);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];
    }

    public function testFullExportWritesYamlToFirstSource(): void
    {
        $source = 'app/etc/configurator/customergroups.yaml';
        $this->givenMaster(['customergroups' => ['sources' => [$source]]]);

        $exported = ['customergroups' => [['name' => 'VIP', 'tax_class' => 'Retail Customer']]];
        $component = $this->givenExportableComponent($exported);
        // Full export builds a single context with isFullExport()==true and no existing data.
        $component->expects($this->once())
            ->method('export')
            ->with($this->callback(
                fn (ExportContext $ctx): bool => $ctx->isFullExport() === true && $ctx->getExistingData() === []
            ))
            ->willReturn($exported);

        $absolute = $this->trackPath($source);
        $result = $this->exporter->export(['customergroups'], true, null, null, false);

        $this->assertSame([$absolute], $result['written']);
        $this->assertSame([], $result['errors']);
        $this->assertFileExists($absolute);
        $this->assertSame($exported, Yaml::parse((string) file_get_contents($absolute)));
    }

    public function testFullExportHonoursExplicitOutputPath(): void
    {
        $source = 'app/etc/configurator/customergroups.yaml';
        $output = $this->basePath . '/app/etc/configurator/elsewhere.yaml';
        $this->givenMaster(['customergroups' => ['sources' => [$source]]]);

        $component = $this->givenExportableComponent(['customergroups' => []]);
        $component->method('export')->willReturn(['customergroups' => []]);

        $this->tempPaths[] = $output;
        $result = $this->exporter->export(['customergroups'], true, null, $output, false);

        // The explicit --output target wins over the master.yaml source path.
        $this->assertSame([$output], $result['written']);
        $this->assertFileExists($output);
    }

    public function testRefreshExportRewritesEachSourceInPlaceWithExistingData(): void
    {
        $source = 'app/etc/configurator/customergroups.yaml';
        $existing = ['customergroups' => [['name' => 'Old']]];
        $absolute = $this->trackPath($source);
        file_put_contents($absolute, Yaml::dump($existing));

        $this->givenMaster(['customergroups' => ['sources' => [$source]]]);

        $refreshed = ['customergroups' => [['name' => 'Refreshed']]];
        $component = $this->givenExportableComponent($refreshed);
        // Refresh mode passes the parsed existing file and isFullExport()==false.
        $component->expects($this->once())
            ->method('export')
            ->with($this->callback(
                fn (ExportContext $ctx): bool => $ctx->isFullExport() === false && $ctx->getExistingData() === $existing
            ))
            ->willReturn($refreshed);

        $result = $this->exporter->export(['customergroups'], false, null, null, false);

        $this->assertSame([$absolute], $result['written']);
        $this->assertSame($refreshed, Yaml::parse((string) file_get_contents($absolute)));
    }

    public function testCsvSourceIsWrittenAsCsvNotYaml(): void
    {
        $source = 'app/etc/configurator/taxrates.csv';
        $this->givenMaster(['taxrates' => ['sources' => [$source]]]);

        $rows = [['Code', 'Rate'], ['VAT', '21'], ['Quote, with comma', '0']];
        $component = $this->givenExportableComponent($rows);
        $component->method('export')->willReturn($rows);

        $absolute = $this->trackPath($source);
        $result = $this->exporter->export(['taxrates'], true, null, null, false);

        $this->assertSame([$absolute], $result['written']);
        $content = (string) file_get_contents($absolute);
        // CSV header row present, comma-bearing field quoted — proves toCsv() ran, not Yaml::dump().
        $this->assertStringContainsString('Code,Rate', $content);
        $this->assertStringContainsString('"Quote, with comma"', $content);
    }

    public function testDryRunWritesNothingButReportsIntent(): void
    {
        $source = 'app/etc/configurator/customergroups.yaml';
        $this->givenMaster(['customergroups' => ['sources' => [$source]]]);

        $component = $this->givenExportableComponent(['customergroups' => []]);
        $component->method('export')->willReturn(['customergroups' => []]);

        $absolute = $this->basePath . '/' . $source;
        $result = $this->exporter->export(['customergroups'], true, null, null, true);

        // The path is reported as "written" (intent) but no file hits disk.
        $this->assertSame([$absolute], $result['written']);
        $this->assertFileDoesNotExist($absolute);
    }

    public function testNonExportableComponentIsSkipped(): void
    {
        $this->givenMaster(['plain' => ['sources' => ['app/etc/configurator/plain.yaml']]]);
        // A component that does not implement ExportableComponentInterface.
        $this->componentList->method('getComponent')->willReturn($this->createMock(ComponentInterface::class));

        $result = $this->exporter->export(['plain'], true, null, null, false);

        $this->assertSame(['plain'], $result['skipped']);
        $this->assertSame([], $result['written']);
    }

    public function testRefreshWithoutSourcesIsSkipped(): void
    {
        // Listed in master.yaml but no sources defined -> refresh has nothing to
        // rewrite -> skipped, error logged. (A full export, by contrast, falls back
        // to a default target; see the next test.)
        $this->givenMaster(['customergroups' => ['sources' => []]]);
        $component = $this->givenExportableComponent([]);
        $component->expects($this->never())->method('export');

        $this->log->expects($this->atLeastOnce())->method('logError');

        $result = $this->exporter->export(['customergroups'], false, null, null, false);

        $this->assertSame(['customergroups'], $result['skipped']);
        $this->assertSame([], $result['written']);
    }

    public function testFullExportFallsBackToDefaultTargetWhenUnwired(): void
    {
        // A component not wired in master.yaml still fully exports, to the
        // conventional app/etc/configurator/<Folder>/<alias>.yaml path.
        $this->givenMaster([]);
        $component = $this->givenExportableComponent(['blocks' => []]);
        $component->method('export')->willReturn(['blocks' => []]);

        $expected = $this->trackPath('app/etc/configurator/Blocks/blocks.yaml');
        $result = $this->exporter->export(['blocks'], true, null, null, false);

        $this->assertSame([$expected], $result['written']);
        $this->assertFileExists($expected);
    }

    public function testRefreshEmptyAliasesUsesMasterKeys(): void
    {
        $this->givenMaster([
            'customergroups' => ['sources' => ['app/etc/configurator/customergroups.yaml']],
            'plain' => ['sources' => ['app/etc/configurator/plain.yaml']],
        ]);

        $exportable = $this->createMock(ExportableComponentStub::class);
        $exportable->method('export')->willReturn(['customergroups' => []]);
        $plain = $this->createMock(ComponentInterface::class);

        // Refresh with no explicit aliases targets the master.yaml keys.
        $this->componentList->method('getComponent')->willReturnMap([
            ['customergroups', $exportable],
            ['plain', $plain],
        ]);

        $cgPath = $this->trackPath('app/etc/configurator/customergroups.yaml');
        file_put_contents($cgPath, Yaml::dump(['customergroups' => []]));
        $result = $this->exporter->export([], false, null, null, false);

        $this->assertSame([$cgPath], $result['written']);
        $this->assertSame(['plain'], $result['skipped']);
    }

    public function testFullEmptyAliasesExportsEveryExportableComponent(): void
    {
        // --all with no --component: every exportable component, independent of
        // master.yaml wiring. 'blocks' has no master entry, so it lands on its
        // default target; the non-exportable component is skipped.
        $this->givenMaster([]);

        $blocks = $this->createMock(ExportableComponentStub::class);
        $blocks->method('export')->willReturn(['b' => []]);
        $plain = $this->createMock(ComponentInterface::class);

        $this->componentList->method('getAllComponents')->willReturn([
            'blocks' => $blocks,
            'plain' => $plain,
        ]);
        $this->componentList->method('getComponent')->willReturnMap([
            ['blocks', $blocks],
            ['plain', $plain],
        ]);

        $blocksPath = $this->trackPath('app/etc/configurator/Blocks/blocks.yaml');
        $result = $this->exporter->export([], true, null, null, false);

        // Only exportable components are enumerated, so 'plain' isn't even a target.
        $this->assertSame([$blocksPath], $result['written']);
    }

    public function testComponentExportFailureIsIsolatedAndRecorded(): void
    {
        $this->givenMaster(['customergroups' => ['sources' => ['app/etc/configurator/customergroups.yaml']]]);

        $component = $this->givenExportableComponent([]);
        $component->method('export')->willThrowException(new \RuntimeException('boom'));

        $this->log->expects($this->atLeastOnce())->method('logError');

        $result = $this->exporter->export(['customergroups'], true, null, null, false);

        // The throw is caught: nothing written, the failure surfaces in errors.
        $this->assertSame([], $result['written']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('customergroups', $result['errors'][0]);
        $this->assertStringContainsString('boom', $result['errors'][0]);
    }

    /**
     * @param array<string, mixed> $master
     */
    private function givenMaster(array $master): void
    {
        $path = $this->basePath . '/app/etc/master.yaml';
        file_put_contents($path, Yaml::dump($master));
        $this->tempPaths[] = $path;
    }

    /**
     * Register an exportable component and route getComponent() to it. The
     * component is returned so the test can refine export() expectations.
     *
     * @param array $defaultExport returned by export() unless the test overrides it
     */
    private function givenExportableComponent(array $defaultExport): ExportableComponentStub&MockObject
    {
        $component = $this->createMock(ExportableComponentStub::class);
        $component->method('export')->willReturn($defaultExport);
        $this->componentList->method('getComponent')->willReturn($component);

        return $component;
    }

    /**
     * Resolve a BP-relative source to its absolute path and schedule it for cleanup.
     */
    private function trackPath(string $source): string
    {
        $absolute = $this->basePath . '/' . ltrim($source, '/');
        $this->tempPaths[] = $absolute;

        return $absolute;
    }
}
