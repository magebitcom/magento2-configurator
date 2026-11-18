<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class StatusStub extends \Magento\Sales\Model\Order\Status
{
    public function getLabel()
    {
        return $this->getData('label');
    }

    public function getState()
    {
        return $this->getData('state');
    }

    public function getStatus()
    {
        return $this->getData('status');
    }
}
