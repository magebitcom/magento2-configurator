<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model\Reconciliation;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Api\VersionManagementInterface;

/**
 * Single source of truth for how every component reconciles config against
 * existing data, combining run mode and per-entity versioning.
 *
 * The decision table the gate encodes:
 *
 *   | state                              | create mode | maintain mode |
 *   |------------------------------------|-------------|---------------|
 *   | not exists                         | Create      | Create        |
 *   | exists, unchanged                  | Skip        | Skip          |
 *   | exists, changed, version bumped    | Update      | Update        |
 *   | exists, changed, no version bump   | Skip        | Update        |
 *
 * Components keep their own load/diff/save mechanics; the gate only decides
 * intent and owns version persistence.
 */
class ReconciliationGate
{
    public function __construct(
        private readonly VersionManagementInterface $versionManagement,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * The id under which this entity's version is tracked. VersionManagement
     * adds its own CONFIG_PREFIX ('version_') on top of this.
     */
    public function versionId(ReconciliationRequest $request): string
    {
        return $request->alias . '_' . $request->key;
    }

    public function isNewVersion(ReconciliationRequest $request): bool
    {
        return $request->version !== null
            && $this->versionManagement->isNewVersion($this->versionId($request), $request->version);
    }

    /**
     * Decide what the component should do with this entity.
     */
    public function decide(ReconciliationRequest $request): ReconciliationOutcome
    {
        if (!$request->exists) {
            return ReconciliationOutcome::Create;
        }

        // An unchanged entity is always skipped, in either mode. A null means
        // the caller could not cheaply diff, so we do not skip on this basis.
        if ($request->unchanged === true) {
            return ReconciliationOutcome::Skip;
        }

        // In create mode existing entities are protected unless their version bumped.
        if ($request->mode === ComponentMode::Create && !$this->isNewVersion($request)) {
            return ReconciliationOutcome::Skip;
        }

        return ReconciliationOutcome::Update;
    }

    /**
     * Persist the entity's version after a successful save. No-op when no
     * version is declared; never writes during a dry run.
     */
    public function commitVersion(ReconciliationRequest $request, bool $dryRun): void
    {
        if ($request->version === null) {
            return;
        }

        $id = $this->versionId($request);

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would set version %d for %s', $request->version, $id));
            return;
        }

        $this->versionManagement->setVersion($id, $request->version);
    }
}
