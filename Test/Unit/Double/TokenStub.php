<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class TokenStub extends \Magento\Integration\Model\Oauth\Token
{
    public function setType($value)
    {
        return $this->setData('type', $value);
    }
}
