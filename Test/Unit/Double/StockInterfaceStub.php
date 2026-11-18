<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

interface StockInterfaceStub extends \Magento\InventoryApi\Api\Data\StockInterface
{
    public function getData($key = '', $index = null);

    public function setData($key, $value = null);
}
