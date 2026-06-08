<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Api;

/**
 * Interface ComponentProcessorInterface
 */
interface ComponentProcessorInterface
{
    /**
     * @param array $data
     *
     * @return $this
     */
    public function setData(array $data);

    /**
     * @param array $config
     *
     * @return $this
     */
    public function setConfig(array $config);

    /**
     * Configure rules
     *
     * @return void
     */
    public function process();
}
