<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class InstanceStub extends \Magento\Widget\Model\Widget\Instance
{
    public function getInstanceType()
    {
        return $this->getData('instance_type');
    }

    public function getThemeId()
    {
        return $this->getData('theme_id');
    }

    public function getTitle()
    {
        return $this->getData('title');
    }
}
