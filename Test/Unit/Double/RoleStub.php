<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class RoleStub extends \Magento\Authorization\Model\Role
{
    public function getRoleName()
    {
        return $this->getData('role_name');
    }

    public function setParentId($value)
    {
        return $this->setData('parent_id', $value);
    }

    public function setRoleName($value)
    {
        return $this->setData('role_name', $value);
    }

    public function setRoleType($value)
    {
        return $this->setData('role_type', $value);
    }

    public function setSortOrder($value)
    {
        return $this->setData('sort_order', $value);
    }

    public function setUserType($value)
    {
        return $this->setData('user_type', $value);
    }
}
