<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class PageStub extends \Magento\Cms\Model\Page
{
    public function setStores($value)
    {
        return $this->setData('stores', $value);
    }
}
