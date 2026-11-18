<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class UserStub extends \Magento\User\Model\User
{
    public function getRoleId()
    {
        return $this->getData('role_id');
    }

    public function setRoleId($value)
    {
        return $this->setData('role_id', $value);
    }
}
