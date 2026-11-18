<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

interface SourceInterfaceStub extends \Magento\InventoryApi\Api\Data\SourceInterface
{
    public function getData($key = '', $index = null);

    public function setData($key, $value = null);
}
