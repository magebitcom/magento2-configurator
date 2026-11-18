<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class RatingStub extends \Magento\Review\Model\Rating
{
    public function getRatingCode()
    {
        return $this->getData('rating_code');
    }

    public function setRatingCode($value)
    {
        return $this->setData('rating_code', $value);
    }

    public function setStores($value)
    {
        return $this->setData('stores', $value);
    }
}
