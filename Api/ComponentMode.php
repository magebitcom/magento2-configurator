<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Api;

/**
 * How a component should reconcile configuration against existing data.
 */
enum ComponentMode: string
{
    /** Create missing entities; leave existing ones untouched. */
    case Create = 'create';

    /** Create missing entities and update existing ones to match the config. */
    case Maintain = 'maintain';
}
