<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Api;

use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;

interface ComponentInterface
{
    /**
     * Process a single configuration source.
     */
    public function execute(ComponentContext $context): ComponentResult;

    /**
     * Unique component alias, as referenced in master.yaml.
     */
    public function getAlias(): string;

    /**
     * Human-readable description of what the component does.
     */
    public function getDescription(): string;
}
