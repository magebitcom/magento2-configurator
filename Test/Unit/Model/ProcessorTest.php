<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\ComponentListInterface;
use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Processor;
use Magento\Framework\App\State;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProcessorTest extends TestCase
{
    private ComponentListInterface&MockObject $componentList;
    private State&MockObject $state;
    private LoggerInterface&MockObject $log;
    private FullModuleList&MockObject $fullModuleList;
    private Dir&MockObject $dir;
    private Manager&MockObject $manager;
    private VersionManagementInterface&MockObject $versionManagement;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->componentList = $this->createMock(ComponentListInterface::class);
        $this->state = $this->createMock(State::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->fullModuleList = $this->createMock(FullModuleList::class);
        $this->dir = $this->createMock(Dir::class);
        $this->manager = $this->createMock(Manager::class);
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);

        $this->processor = new Processor(
            $this->componentList,
            $this->state,
            $this->log,
            $this->fullModuleList,
            $this->dir,
            $this->manager,
            $this->versionManagement
        );

        $this->processor->setEnvironment('test');
    }

    public function testAddComponentIsFluentAndTracked(): void
    {
        $return = $this->processor->addComponent('config');

        $this->assertSame($this->processor, $return);
        $this->assertSame(['config' => 'config'], $this->processor->getComponents());
    }

    public function testSetEnvironmentIsFluentAndStored(): void
    {
        $return = $this->processor->setEnvironment('staging');

        $this->assertSame($this->processor, $return);
        $this->assertSame('staging', $this->processor->getEnvironment());
    }

    public function testDryRunFlagCoercesToBool(): void
    {
        $this->assertFalse($this->processor->isDryRun());

        $this->processor->setDryRun(1);

        $this->assertTrue($this->processor->isDryRun());
    }

    public function testIgnoreMissingFilesFlagRoundTrips(): void
    {
        $this->assertFalse($this->processor->isIgnoreMissingFiles());

        $this->processor->setIgnoreMissingFiles(true);

        $this->assertTrue($this->processor->isIgnoreMissingFiles());
    }

    public function testGetRunResultIsLazilyInitialised(): void
    {
        $result = $this->processor->getRunResult();

        $this->assertInstanceOf(ComponentResult::class, $result);
        $this->assertSame(0, $result->getCreated());
        // Same instance returned on subsequent calls.
        $this->assertSame($result, $this->processor->getRunResult());
    }

    public function testRunComponentResolvesEnvironmentModeAndMergesResult(): void
    {
        $componentResult = new ComponentResult();
        $componentResult->recordCreated(2);

        // The processor must build a context in Maintain mode (from the env node)
        // and fold the component's result into the run total.
        $component = $this->givenComponentExpecting(ComponentMode::Maintain, false, $componentResult);

        $this->processor->runComponent('config', [
            'env' => [
                'test' => [
                    'mode' => 'maintain',
                    'sources' => ['config/test.yaml'],
                ],
            ],
        ]);

        $this->assertSame(2, $this->processor->getRunResult()->getCreated());
        $this->assertTrue($this->processor->getRunResult()->isSuccessful());
    }

    public function testRunComponentDefaultsToCreateModeWhenNoEnvMode(): void
    {
        // Top-level sources with no env node -> default Create mode.
        $component = $this->givenComponentExpecting(ComponentMode::Create, false, new ComponentResult());

        $this->processor->runComponent('config', [
            'sources' => ['config/test.yaml'],
        ]);

        $this->assertTrue($this->processor->getRunResult()->isSuccessful());
    }

    public function testRunComponentThreadsDryRunIntoContext(): void
    {
        $this->processor->setDryRun(true);

        $component = $this->givenComponentExpecting(ComponentMode::Create, true, new ComponentResult());

        $this->processor->runComponent('config', [
            'sources' => ['config/test.yaml'],
        ]);

        $this->assertTrue($this->processor->getRunResult()->isSuccessful());
    }

    public function testRunComponentProcessesBothGlobalAndEnvironmentSources(): void
    {
        $result = new ComponentResult();
        $result->recordUpdated(1);

        $component = $this->createMock(ComponentInterface::class);
        // One global source + one env source = two executions.
        $component->expects($this->exactly(2))->method('execute')->willReturn($result);
        $this->componentList->method('getComponent')->willReturn($component);

        $this->processor->runComponent('config', [
            'sources' => ['config/global.yaml'],
            'env' => [
                'test' => [
                    'sources' => ['config/env.yaml'],
                ],
            ],
        ]);

        // Both source results merged.
        $this->assertSame(2, $this->processor->getRunResult()->getUpdated());
    }

    public function testRunComponentIsolatesComponentFailureAsError(): void
    {
        $component = $this->createMock(ComponentInterface::class);
        $component->method('execute')->willThrowException(new \RuntimeException('boom'));
        $this->componentList->method('getComponent')->willReturn($component);

        $this->log->expects($this->atLeastOnce())->method('logError');

        $this->processor->runComponent('config', [
            'sources' => ['config/test.yaml'],
        ]);

        $runResult = $this->processor->getRunResult();
        $this->assertFalse($runResult->isSuccessful());
        $this->assertNotEmpty($runResult->getErrors());
        $this->assertStringContainsString('boom', $runResult->getErrors()[0]);
    }

    public function testRunComponentSkipsWhenSourceVersionIsStale(): void
    {
        // version declared but not new -> whole component skipped, never executed.
        $this->versionManagement->method('isNewVersion')->with('source_config', 5)->willReturn(false);
        $this->versionManagement->expects($this->never())->method('setVersion');

        $component = $this->createMock(ComponentInterface::class);
        $component->expects($this->never())->method('execute');
        $this->componentList->method('getComponent')->willReturn($component);

        $this->processor->runComponent('config', [
            'version' => 5,
            'sources' => ['config/test.yaml'],
        ]);

        $this->assertSame(0, $this->processor->getRunResult()->getCreated());
    }

    public function testRunComponentPersistsSourceVersionAfterCleanRun(): void
    {
        $this->versionManagement->method('isNewVersion')->with('source_config', 5)->willReturn(true);
        // Clean run (no errors) commits the source version.
        $this->versionManagement->expects($this->once())->method('setVersion')->with('source_config', 5);

        $component = $this->givenComponentExpecting(ComponentMode::Create, false, new ComponentResult());

        $this->processor->runComponent('config', [
            'version' => 5,
            'sources' => ['config/test.yaml'],
        ]);

        $this->assertTrue($this->processor->getRunResult()->isSuccessful());
    }

    public function testRunComponentDoesNotPersistVersionWhenComponentErrors(): void
    {
        $this->versionManagement->method('isNewVersion')->willReturn(true);
        // A failing run must NOT commit the version, so it retries next deploy.
        $this->versionManagement->expects($this->never())->method('setVersion');

        $component = $this->createMock(ComponentInterface::class);
        $component->method('execute')->willThrowException(new \RuntimeException('kaboom'));
        $this->componentList->method('getComponent')->willReturn($component);

        $this->processor->runComponent('config', [
            'version' => 5,
            'sources' => ['config/test.yaml'],
        ]);

        $this->assertFalse($this->processor->getRunResult()->isSuccessful());
    }

    public function testRunComponentDoesNotPersistVersionDuringDryRun(): void
    {
        $this->processor->setDryRun(true);
        $this->versionManagement->method('isNewVersion')->willReturn(true);
        // Dry run records intent but never writes the source version.
        $this->versionManagement->expects($this->never())->method('setVersion');

        $component = $this->givenComponentExpecting(ComponentMode::Create, true, new ComponentResult());

        $this->processor->runComponent('config', [
            'version' => 5,
            'sources' => ['config/test.yaml'],
        ]);

        $this->assertTrue($this->processor->getRunResult()->isSuccessful());
    }

    public function testRunComponentSkipsMissingFileWhenIgnoreFlagSet(): void
    {
        $this->processor->setIgnoreMissingFiles(true);

        // A ComponentException (e.g. missing file) is swallowed when ignoring missing files;
        // it is logged as info and does not become a run error.
        $component = $this->createMock(ComponentInterface::class);
        $component->method('execute')->willThrowException(new ComponentException('missing file'));
        $this->componentList->method('getComponent')->willReturn($component);

        $this->log->expects($this->atLeastOnce())->method('logInfo');

        $this->processor->runComponent('config', [
            'sources' => ['config/missing.yaml'],
        ]);

        $this->assertTrue($this->processor->getRunResult()->isSuccessful());
    }

    /**
     * Stub a component that asserts the context the processor builds (mode +
     * dry-run) and returns the given result.
     */
    private function givenComponentExpecting(
        ComponentMode $expectedMode,
        bool $expectedDryRun,
        ComponentResult $result
    ): ComponentInterface&MockObject {
        $component = $this->createMock(ComponentInterface::class);
        $component->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (ComponentContext $context) use ($expectedMode, $expectedDryRun): bool {
                return $context->getMode() === $expectedMode
                    && $context->isDryRun() === $expectedDryRun
                    && $context->getEnvironment() === 'test';
            }))
            ->willReturn($result);

        $this->componentList->method('getComponent')->willReturn($component);

        return $component;
    }
}
