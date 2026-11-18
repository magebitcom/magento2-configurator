<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class BlockStub extends \Magento\Cms\Model\Block
{
    public function getIsActive()
    {
        return $this->getData('is_active');
    }

    public function getStoreId()
    {
        return $this->getData('store_id');
    }

    public function setStoreId($value)
    {
        return $this->setData('store_id', $value);
    }

    public function setStores($value)
    {
        return $this->setData('stores', $value);
    }
}
