<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Model\ComponentContext;
use PHPUnit\Framework\TestCase;

class ComponentContextTest extends TestCase
{
    public function testGetSourcePathReturnsConstructorValue(): void
    {
        $context = new ComponentContext(
            'app/etc/widgets.yaml',
            ComponentMode::Create,
            'production',
            false,
            static fn (): array => []
        );

        $this->assertSame('app/etc/widgets.yaml', $context->getSourcePath());
    }

    public function testGetModeReturnsConstructorValue(): void
    {
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Maintain,
            'test',
            false,
            static fn (): array => []
        );

        $this->assertSame(ComponentMode::Maintain, $context->getMode());
    }

    public function testGetEnvironmentReturnsConstructorValue(): void
    {
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'staging',
            false,
            static fn (): array => []
        );

        $this->assertSame('staging', $context->getEnvironment());
    }

    public function testIsDryRunReflectsConstructorFlag(): void
    {
        $dry = new ComponentContext('test.yaml', ComponentMode::Create, 'test', true, static fn (): array => []);
        $live = new ComponentContext('test.yaml', ComponentMode::Create, 'test', false, static fn (): array => []);

        $this->assertTrue($dry->isDryRun());
        $this->assertFalse($live->isDryRun());
    }

    public function testGetVersionReturnsDeclaredVersion(): void
    {
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'test',
            false,
            static fn (): array => [],
            7
        );

        $this->assertSame(7, $context->getVersion());
    }

    public function testGetVersionDefaultsToNull(): void
    {
        $context = new ComponentContext('test.yaml', ComponentMode::Create, 'test', false, static fn (): array => []);

        $this->assertNull($context->getVersion());
    }

    public function testGetDataReturnsParsedSourceArray(): void
    {
        $expected = ['widgets' => [['type' => 'cms_block']]];
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'test',
            false,
            static fn (): array => $expected
        );

        $this->assertSame($expected, $context->getData());
    }

    public function testGetDataPassesSourcePathToParser(): void
    {
        $context = new ComponentContext(
            'path/to/source.yaml',
            ComponentMode::Create,
            'test',
            false,
            static fn (string $path): array => ['seen' => $path]
        );

        $this->assertSame(['seen' => 'path/to/source.yaml'], $context->getData());
    }

    public function testGetDataMemoisesParserResultAcrossCalls(): void
    {
        $calls = 0;
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'test',
            false,
            static function () use (&$calls): array {
                $calls++;

                return ['n' => $calls];
            }
        );

        $first = $context->getData();
        $second = $context->getData();

        // Parser invoked exactly once; both calls yield the identical memoised array.
        $this->assertSame(1, $calls);
        $this->assertSame(['n' => 1], $first);
        $this->assertSame($first, $second);
    }

    public function testGetDataDoesNotParseUntilFirstCall(): void
    {
        $parsed = false;
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'test',
            false,
            static function () use (&$parsed): array {
                $parsed = true;

                return [];
            }
        );

        // Constructing the context (and reading other accessors) must not trigger a parse.
        $context->getSourcePath();
        $context->getMode();
        $this->assertFalse($parsed);

        $context->getData();
        $this->assertTrue($parsed);
    }

    public function testGetDataMemoisesEmptyArrayWithoutReparsing(): void
    {
        $calls = 0;
        $context = new ComponentContext(
            'test.yaml',
            ComponentMode::Create,
            'test',
            false,
            static function () use (&$calls): array {
                $calls++;

                return [];
            }
        );

        $this->assertSame([], $context->getData());
        $this->assertSame([], $context->getData());
        $this->assertSame(1, $calls);
    }
}
