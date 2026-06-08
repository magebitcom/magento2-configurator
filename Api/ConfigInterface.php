<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Api;

use Magebit\Configurator\Model\Component\ComponentAbstract;

interface ConfigInterface
{

    /**
     * Gets all the different available components
     * @return array
     */
    public function getAllComponents();

    /**
     * Gets a single component by its name
     *
     * @param String $name
     * @return ComponentAbstract
     */
    public function getComponentByName($name);
}
