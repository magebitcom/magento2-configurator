<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class CustomerStub extends \Magento\Customer\Model\Customer
{
    public function getEmail()
    {
        return $this->getData('email');
    }
}
