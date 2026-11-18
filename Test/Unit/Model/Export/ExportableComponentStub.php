<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model\Export;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\ExportableComponentInterface;

interface ExportableComponentStub extends ComponentInterface, ExportableComponentInterface
{
}
