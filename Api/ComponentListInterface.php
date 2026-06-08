<?php

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
