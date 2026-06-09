<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model;

use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\Logging;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class LoggingTest extends TestCase
{
    private ConsoleOutput&MockObject $output;

    protected function setUp(): void
    {
        $this->output = $this->createMock(ConsoleOutput::class);
    }

    public function testDefaultLogLevelIsVerbosityNormal(): void
    {
        $logging = new Logging($this->output);

        $this->assertSame(OutputInterface::VERBOSITY_NORMAL, $logging->getLogLevel());
    }

    public function testSetLogLevelStoresValueAndReturnsSelf(): void
    {
        $logging = new Logging($this->output);

        $returned = $logging->setLogLevel(OutputInterface::VERBOSITY_DEBUG);

        $this->assertSame($logging, $returned);
        $this->assertSame(OutputInterface::VERBOSITY_DEBUG, $logging->getLogLevel());
    }

    public function testLogWrapsMessageInLevelTags(): void
    {
        $this->output->expects($this->once())
            ->method('writeln')
            ->with('<error>boom</error>');

        $logging = new Logging($this->output);
        $logging->log('boom', LoggerInterface::LEVEL_ERROR);
    }

    public function testLogPrependsNestPrefixPerLevel(): void
    {
        // nest=2 prepends two "| " segments before the tagged message.
        $this->output->expects($this->once())
            ->method('writeln')
            ->with('| | <info>deep</info>');

        $logging = new Logging($this->output);
        $logging->log('deep', LoggerInterface::LEVEL_INFO, 2);
    }

    public function testLogStringifiesArrayMessages(): void
    {
        $captured = null;
        $this->output->expects($this->once())
            ->method('writeln')
            ->willReturnCallback(function ($line) use (&$captured): void {
                $captured = $line;
            });

        $logging = new Logging($this->output);
        $logging->log(['a' => 1], LoggerInterface::LEVEL_COMMENT);

        $this->assertStringContainsString('Log array:', (string)$captured);
        $this->assertStringContainsString('[a] => 1', (string)$captured);
        $this->assertStringStartsWith('<comment>', (string)$captured);
    }

    public function testLogErrorUsesErrorLevelTag(): void
    {
        $this->output->expects($this->once())
            ->method('writeln')
            ->with('<error>failed</error>');

        $logging = new Logging($this->output);
        $logging->logError('failed');
    }

    public function testLogQuestionUsesQuestionLevelTag(): void
    {
        $this->output->expects($this->once())
            ->method('writeln')
            ->with('<question>are you sure?</question>');

        $logging = new Logging($this->output);
        $logging->logQuestion('are you sure?');
    }

    public function testLogCommentWritesOnlyAboveNormalVerbosity(): void
    {
        // At VERBOSITY_NORMAL the comment is below threshold and must be suppressed.
        $this->output->expects($this->never())->method('writeln');

        $logging = new Logging($this->output, OutputInterface::VERBOSITY_NORMAL);
        $logging->logComment('chatter');
    }

    public function testLogCommentWritesWhenVerbose(): void
    {
        $this->output->expects($this->once())
            ->method('writeln')
            ->with('<comment>chatter</comment>');

        $logging = new Logging($this->output, OutputInterface::VERBOSITY_VERBOSE);
        $logging->logComment('chatter');
    }

    public function testLogInfoSuppressedWhenQuiet(): void
    {
        // VERBOSITY_QUIET is the floor; logInfo only writes strictly above it.
        $this->output->expects($this->never())->method('writeln');

        $logging = new Logging($this->output, OutputInterface::VERBOSITY_QUIET);
        $logging->logInfo('hello');
    }

    public function testLogInfoWritesAtNormalVerbosity(): void
    {
        $this->output->expects($this->once())
            ->method('writeln')
            ->with('<info>hello</info>');

        $logging = new Logging($this->output, OutputInterface::VERBOSITY_NORMAL);
        $logging->logInfo('hello');
    }

    public function testLogInfoHonoursNesting(): void
    {
        $this->output->expects($this->once())
            ->method('writeln')
            ->with('| <info>nested</info>');

        $logging = new Logging($this->output, OutputInterface::VERBOSITY_NORMAL);
        $logging->logInfo('nested', 1);
    }
}
