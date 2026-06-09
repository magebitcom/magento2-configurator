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

/**
 * Immutable description of a single entity a component is about to reconcile.
 * Bundled into one object so the gate's signature stays stable as inputs grow.
 */
final class ReconciliationRequest
{
    /**
     * @param string $alias Component alias, e.g. 'config'. Prefixed onto the version id.
     * @param string $key Scope-qualified entity key WITHOUT the alias prefix
     *                    (e.g. 'global_web/secure/base_url', a CMS block identifier).
     * @param ComponentMode $mode Resolved run mode for this component.
     * @param bool $exists Whether the entity already exists in the store.
     * @param int|null $version Desired version from config, or null when none is declared.
     * @param bool|null $unchanged True/false when the caller can cheaply diff; null when
     *                             it cannot (the gate then never skips on this basis and the
     *                             component does its own field diff after deciding intent).
     */
    public function __construct(
        public readonly string $alias,
        public readonly string $key,
        public readonly ComponentMode $mode,
        public readonly bool $exists,
        public readonly ?int $version = null,
        public readonly ?bool $unchanged = null
    ) {
    }
}
