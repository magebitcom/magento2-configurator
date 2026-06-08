<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Api\Data;

interface ConfigInterface
{
    public const ID = 'config_id';
    public const NAME = 'name';
    public const VALUE = 'value';

    public function getName(): string;

    public function setName(string $name): self;

    public function getValue(): string;

    public function setValue(string $value): self;
}
