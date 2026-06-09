<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Api;

use Magebit\Configurator\Model\ComponentResult;

/**
 * What a component should do with a single entity once the ReconciliationGate
 * has weighed its mode, version and changed/unchanged state.
 */
enum ReconciliationOutcome: string
{
    /** Entity does not exist yet — create it. */
    case Create = 'create';

    /** Entity exists and should be updated to match the config. */
    case Update = 'update';

    /** Leave the existing entity untouched. */
    case Skip = 'skip';

    public function isSkip(): bool
    {
        return $this === self::Skip;
    }

    /**
     * Record this outcome against a component result, so call sites don't
     * each re-implement the create/update/skip mapping.
     */
    public function record(ComponentResult $result, int $count = 1): void
    {
        match ($this) {
            self::Create => $result->recordCreated($count),
            self::Update => $result->recordUpdated($count),
            self::Skip => $result->recordSkipped($count),
        };
    }
}
