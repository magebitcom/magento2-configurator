<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Api;

interface ComponentInterface
{
    /**
     * @param array $data
     * @return void
     */
    public function execute($data);

    /**
     * @return string
     */
    public function getAlias();

    /**
     * @return string
     */
    public function getDescription();
}
