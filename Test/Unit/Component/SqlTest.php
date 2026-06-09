<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Component\Processor\SqlSplitProcessor;
use Magebit\Configurator\Component\Sql;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SqlTest extends TestCase
{
    private SqlSplitProcessor&MockObject $processor;
    private LoggerInterface&MockObject $log;
    private Sql $component;

    /** @var string base path the component resolves SQL files against (the BP constant) */
    private string $basePath;

    /** @var string[] absolute paths of temp files created for a test, cleaned up in tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->processor = $this->createMock(SqlSplitProcessor::class);
        $this->log = $this->createMock(LoggerInterface::class);

        // The component resolves file paths as `BP . '/' . $sqlFile`. Pin BP to a real
        // temp directory so file_exists() is deterministic for these tests.
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }
        $this->basePath = BP;

        $this->component = new Sql($this->processor, $this->log);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function testExecutesExistingSqlFile(): void
    {
        $relative = $this->givenSqlFileExists('migration.sql');

        $this->processor->expects($this->once())
            ->method('process')
            ->with('migration', $this->basePath . '/' . $relative);

        $result = $this->execute(['migration' => $relative]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testProcessesEverySqlFileInTheNode(): void
    {
        $first = $this->givenSqlFileExists('a.sql');
        $second = $this->givenSqlFileExists('b.sql');

        $this->processor->expects($this->exactly(2))->method('process');

        $result = $this->execute(['first' => $first, 'second' => $second]);

        $this->assertSame(2, $result->getCreated());
    }

    public function testSkipsMissingFileWithoutExecutingOrRecording(): void
    {
        // Reference a file that is guaranteed not to exist under BP.
        $missing = 'definitely-missing-' . uniqid('', true) . '.sql';

        $this->processor->expects($this->never())->method('process');
        $this->log->expects($this->atLeastOnce())->method('logError');

        $result = $this->execute(['missing' => $missing]);

        // Missing files are skipped silently (continue) — not counted, not an error row.
        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
    }

    public function testDryRunDoesNotExecuteSqlButRecordsIntent(): void
    {
        $relative = $this->givenSqlFileExists('dry.sql');

        // Dry-run guard: the processor must never touch the database.
        $this->processor->expects($this->never())->method('process');

        $result = $this->execute(['dry' => $relative], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenSqlNodeMissing(): void
    {
        $this->processor->expects($this->never())->method('process');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenSqlNodeIsNotAnArray(): void
    {
        $this->processor->expects($this->never())->method('process');

        $result = $this->execute([], false, ['sql' => 'not-an-array']);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    /**
     * @param array $sql value of the `sql` node (name => relative file path)
     * @param array|null $rawData full source override (bypasses $sql)
     */
    private function execute(
        array $sql,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['sql' => $sql];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    /**
     * Creates a real (empty) file under BP and returns its path relative to BP,
     * which is what the YAML source supplies to the component.
     */
    private function givenSqlFileExists(string $name): string
    {
        $relative = 'magebit_configurator_test_' . uniqid('', true) . '_' . $name;
        $absolute = $this->basePath . '/' . $relative;
        file_put_contents($absolute, '');
        $this->tempFiles[] = $absolute;

        return $relative;
    }
}
