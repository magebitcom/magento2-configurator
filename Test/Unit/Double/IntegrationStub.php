<?php
/**
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Double;

class IntegrationStub extends \Magento\Integration\Model\Integration
{
    public function getConsumerId()
    {
        return $this->getData('consumer_id');
    }

    public function getEmail()
    {
        return $this->getData('email');
    }

    public function getEndpoint()
    {
        return $this->getData('endpoint');
    }

    public function getIdentityLinkUrl()
    {
        return $this->getData('identity_link_url');
    }

    public function getName()
    {
        return $this->getData('name');
    }
}
