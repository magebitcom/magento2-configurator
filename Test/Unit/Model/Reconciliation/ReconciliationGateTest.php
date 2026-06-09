<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model\Reconciliation;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReconciliationGateTest extends TestCase
{
    private VersionManagementInterface&MockObject $versionManagement;
    private LoggerInterface&MockObject $log;
    private ReconciliationGate $gate;

    protected function setUp(): void
    {
        $this->versionManagement = $this->createMock(VersionManagementInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->gate = new ReconciliationGate($this->versionManagement, $this->log);
    }

    public function testVersionIdComposesAliasAndKey(): void
    {
        $request = new ReconciliationRequest('config', 'global_web/secure/base_url', ComponentMode::Maintain, true);

        $this->assertSame('config_global_web/secure/base_url', $this->gate->versionId($request));
    }

    public function testMissingEntityIsAlwaysCreate(): void
    {
        foreach ([ComponentMode::Create, ComponentMode::Maintain] as $mode) {
            $request = new ReconciliationRequest('widgets', 'k', $mode, false);
            $this->assertSame(ReconciliationOutcome::Create, $this->gate->decide($request));
        }
    }

    public function testUnchangedEntityIsAlwaysSkip(): void
    {
        foreach ([ComponentMode::Create, ComponentMode::Maintain] as $mode) {
            $request = new ReconciliationRequest('widgets', 'k', $mode, true, null, true);
            $this->assertSame(ReconciliationOutcome::Skip, $this->gate->decide($request));
        }
    }

    public function testExistingChangedInCreateModeIsSkipped(): void
    {
        $request = new ReconciliationRequest('widgets', 'k', ComponentMode::Create, true, null, false);

        $this->assertSame(ReconciliationOutcome::Skip, $this->gate->decide($request));
    }

    public function testExistingChangedInMaintainModeIsUpdated(): void
    {
        $request = new ReconciliationRequest('widgets', 'k', ComponentMode::Maintain, true, null, false);

        $this->assertSame(ReconciliationOutcome::Update, $this->gate->decide($request));
    }

    public function testVersionBumpForcesUpdateEvenInCreateMode(): void
    {
        $this->versionManagement->method('isNewVersion')->with('widgets_k', 5)->willReturn(true);

        $request = new ReconciliationRequest('widgets', 'k', ComponentMode::Create, true, 5, false);

        $this->assertSame(ReconciliationOutcome::Update, $this->gate->decide($request));
    }

    public function testStaleVersionInCreateModeIsStillSkipped(): void
    {
        $this->versionManagement->method('isNewVersion')->with('widgets_k', 5)->willReturn(false);

        $request = new ReconciliationRequest('widgets', 'k', ComponentMode::Create, true, 5, false);

        $this->assertSame(ReconciliationOutcome::Skip, $this->gate->decide($request));
    }

    public function testUnchangedShortCircuitsEvenWhenExistsIsFalse(): void
    {
        // Config maps an existing-but-empty value as exists=false yet unchanged=true;
        // the gate must still skip (the value already matches).
        $request = new ReconciliationRequest('config', 'global_k', ComponentMode::Maintain, false, null, true);

        $this->assertSame(ReconciliationOutcome::Skip, $this->gate->decide($request));
    }

    public function testUnknownDiffDoesNotSkipOnUnchangedBasis(): void
    {
        // unchanged = null (caller can't tell) -> must not be treated as unchanged.
        $request = new ReconciliationRequest('widgets', 'k', ComponentMode::Maintain, true, null, null);

        $this->assertSame(ReconciliationOutcome::Update, $this->gate->decide($request));
    }

    public function testCommitVersionPersistsWhenNotDryRun(): void
    {
        $this->versionManagement->expects($this->once())->method('setVersion')->with('config_global_k', 7);

        $request = new ReconciliationRequest('config', 'global_k', ComponentMode::Maintain, true, 7);
        $this->gate->commitVersion($request, false);
    }

    public function testCommitVersionNeverPersistsDuringDryRun(): void
    {
        $this->versionManagement->expects($this->never())->method('setVersion');

        $request = new ReconciliationRequest('config', 'global_k', ComponentMode::Maintain, true, 7);
        $this->gate->commitVersion($request, true);
    }

    public function testCommitVersionIsNoOpWhenNoVersionDeclared(): void
    {
        $this->versionManagement->expects($this->never())->method('setVersion');

        $request = new ReconciliationRequest('config', 'global_k', ComponentMode::Maintain, true, null);
        $this->gate->commitVersion($request, false);
    }
}
