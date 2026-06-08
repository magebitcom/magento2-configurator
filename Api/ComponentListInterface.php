<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Api;

interface ComponentListInterface
{
    /**
     * @param $componentAlias
     * @return ComponentInterface|bool
     */
    public function getComponent($componentAlias);

    /**
     * @return ComponentInterface[]
     */
    public function getAllComponents();
}
