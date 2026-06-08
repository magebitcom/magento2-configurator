<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentListInterface;

class ComponentList implements ComponentListInterface
{
    /**
     * @var []
     */
    private $components;

    public function __construct(
        array $components = []
    ) {
        $this->components = $components;
    }

    /**
     * @inheritDoc
     */
    public function getComponent($componentAlias)
    {
        if (array_key_exists($componentAlias, $this->components) === true) {
            return $this->components[$componentAlias];
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getAllComponents()
    {
        return $this->components;
    }
}
