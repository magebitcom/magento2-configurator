<?php
/**
 * This file is part of the CtiDigital_Configurator package.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade CtiDigital_Configurator
 * to newer versions in the future.
 *
 * @copyright Copyright (c) 2023 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace CtiDigital\Configurator\Api\Data;

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
