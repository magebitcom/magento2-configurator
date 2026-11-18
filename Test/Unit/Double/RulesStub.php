<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class RulesStub extends \Magento\Authorization\Model\Rules
{
    public function getResourceId()
    {
        return $this->getData('resource_id');
    }

    public function setResources($value)
    {
        return $this->setData('resources', $value);
    }

    public function setRoleId($value)
    {
        return $this->setData('role_id', $value);
    }
}
