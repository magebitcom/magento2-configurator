<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Component\Sequence;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\SalesSequence\Model\Builder;
use Magento\SalesSequence\Model\Config;
use Magento\SalesSequence\Model\EntityPool;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SequenceTest extends TestCase
{
    private Builder&MockObject $sequenceBuilder;
    private EntityPool&MockObject $entityPool;
    private Config&MockObject $sequenceConfig;
    private StoreRepositoryInterface&MockObject $storeRepository;
    private LoggerInterface&MockObject $log;
    private Sequence $component;

    protected function setUp(): void
    {
        $this->sequenceBuilder = $this->createMock(Builder::class);
        $this->entityPool = $this->createMock(EntityPool::class);
        $this->sequenceConfig = $this->createMock(Config::class);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);

        // The config supplies defaults for every recognised key; tests override per-store.
        $this->sequenceConfig->method('get')->willReturn('default');

        $this->component = new Sequence(
            $this->sequenceBuilder,
            $this->entityPool,
            $this->sequenceConfig,
            $this->storeRepository,
            $this->log
        );
    }

    public function testCreatesSequenceTablePerEntityForStore(): void
    {
        $this->givenStore('default', 1);
        $this->givenEntities(['order', 'invoice']);

        // Fluent builder: each setter chains, create() persists one sequence table.
        $this->givenFluentBuilder();
        $this->sequenceBuilder->expects($this->exactly(2))->method('create');

        $result = $this->execute(['default' => []]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getCreated());
    }

    public function testAppliesPerStoreOverridesToBuilder(): void
    {
        $this->givenStore('default', 1);
        $this->givenEntities(['order']);

        $this->sequenceBuilder->method('setSuffix')->willReturnSelf();
        $this->sequenceBuilder->method('setStartValue')->willReturnSelf();
        $this->sequenceBuilder->method('setStoreId')->willReturnSelf();
        $this->sequenceBuilder->method('setStep')->willReturnSelf();
        $this->sequenceBuilder->method('setWarningValue')->willReturnSelf();
        $this->sequenceBuilder->method('setMaxValue')->willReturnSelf();
        $this->sequenceBuilder->method('setEntityType')->willReturnSelf();
        $this->sequenceBuilder->method('create')->willReturnSelf();

        // Overridden prefix flows straight onto the builder; defaults stay otherwise.
        $this->sequenceBuilder->expects($this->once())->method('setPrefix')->with('US')->willReturnSelf();

        $result = $this->execute(['default' => ['prefix' => 'US']]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testDefaultsStorePrefixToStoreIdWhenNotOverridden(): void
    {
        $this->givenStore('default', 7);
        $this->givenEntities(['order']);
        $this->givenFluentBuilder();

        // No 'prefix' override -> falls back to the store id.
        $this->sequenceBuilder->expects($this->once())->method('setPrefix')->with(7)->willReturnSelf();

        $result = $this->execute(['default' => []]);

        $this->assertSame(1, $result->getCreated());
    }

    public function testDryRunRecordsIntentWithoutBuilding(): void
    {
        $this->givenStore('default', 1);
        $this->givenEntities(['order', 'invoice']);

        // Dry-run must not touch the builder at all, yet still record what it would create.
        $this->sequenceBuilder->expects($this->never())->method('create');
        $this->sequenceBuilder->expects($this->never())->method('setPrefix');

        $result = $this->execute(['default' => []], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getCreated());
    }

    public function testRecordsErrorWhenStoresNodeMissing(): void
    {
        $this->storeRepository->expects($this->never())->method('get');
        $this->sequenceBuilder->expects($this->never())->method('create');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenStoresNodeIsNotArray(): void
    {
        $this->sequenceBuilder->expects($this->never())->method('create');

        $result = $this->execute([], false, ['stores' => 'not-an-array']);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testCatchesUnknownStoreAndContinues(): void
    {
        // First store throws (unknown code), second resolves and builds its tables.
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $store->method('getCode')->willReturn('eu');

        $this->storeRepository->method('get')->willReturnCallback(
            function (string $code) use ($store): StoreInterface {
                if ($code === 'missing') {
                    throw new \Exception('The store that was requested wasn\'t found.');
                }

                return $store;
            }
        );

        $this->givenEntities(['order']);
        $this->givenFluentBuilder();
        $this->sequenceBuilder->expects($this->once())->method('create');

        $result = $this->execute(['missing' => [], 'eu' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
        $this->assertSame(1, $result->getCreated());
    }

    /**
     * @param array $stores value of the `stores` node (keyed by store code)
     * @param array|null $rawData full source override (bypasses $stores)
     */
    private function execute(
        array $stores,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['stores' => $stores];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    private function givenStore(string $code, int $id): StoreInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $this->storeRepository->method('get')->with($code)->willReturn($store);

        return $store;
    }

    /**
     * @param string[] $entities
     */
    private function givenEntities(array $entities): void
    {
        $this->entityPool->method('getEntities')->willReturn($entities);
    }

    private function givenFluentBuilder(): void
    {
        $this->sequenceBuilder->method('setPrefix')->willReturnSelf();
        $this->sequenceBuilder->method('setSuffix')->willReturnSelf();
        $this->sequenceBuilder->method('setStartValue')->willReturnSelf();
        $this->sequenceBuilder->method('setStoreId')->willReturnSelf();
        $this->sequenceBuilder->method('setStep')->willReturnSelf();
        $this->sequenceBuilder->method('setWarningValue')->willReturnSelf();
        $this->sequenceBuilder->method('setMaxValue')->willReturnSelf();
        $this->sequenceBuilder->method('setEntityType')->willReturnSelf();
        $this->sequenceBuilder->method('create')->willReturnSelf();
    }
}
