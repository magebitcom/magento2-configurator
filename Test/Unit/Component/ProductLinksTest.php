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
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Component\ProductLinks;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductLinksTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $productRepository;
    private ProductLinkInterfaceFactory&MockObject $productLinkFactory;
    private LoggerInterface&MockObject $log;
    private ProductLinks $component;

    /** @var array<string, ProductInterface&MockObject> */
    private array $products = [];

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->productLinkFactory = $this->getMockBuilder(ProductLinkInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->log = $this->createMock(LoggerInterface::class);

        // get() resolves products from a per-test registry keyed by SKU.
        $this->productRepository->method('get')->willReturnCallback(
            fn (string $sku): ProductInterface => $this->products[$sku]
                ?? throw new \Magento\Framework\Exception\NoSuchEntityException()
        );

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new ProductLinks(
            $this->productRepository,
            $this->productLinkFactory,
            $this->log,
            $gate
        );
    }

    public function testCreatesLinksWhenProductHasNoneOfThatType(): void
    {
        $parent = $this->givenProduct('parent', []);
        $this->givenProduct('child-a', []);
        $this->givenProduct('child-b', []);

        // No existing links of this type -> gate decides Create -> persists.
        $parent->expects($this->once())->method('setProductLinks');
        $this->productRepository->expects($this->once())->method('save')->with($parent);
        $this->givenLinkFactoryYieldsLinks();

        $result = $this->execute(['relation' => ['parent' => ['child-a', 'child-b']]]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testCreateModeProtectsProductWithExistingLinksOfType(): void
    {
        $existing = $this->createMock(ProductLinkInterface::class);
        $existing->method('getLinkType')->willReturn('related');
        $this->givenProduct('parent', [$existing]);
        $this->givenProduct('child-a', []);

        // Existing related links + create mode + no version bump -> skip, no save.
        $this->productLinkFactory->expects($this->never())->method('create');
        $this->productRepository->expects($this->never())->method('save');

        $result = $this->execute(['relation' => ['parent' => ['child-a']]]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getUpdated());
    }

    public function testCreateModeIgnoresExistingLinksOfADifferentType(): void
    {
        // Parent already has a cross_sell link, but we are configuring relations,
        // so for the relation entity the parent counts as "no links of type".
        $other = $this->createMock(ProductLinkInterface::class);
        $other->method('getLinkType')->willReturn('crosssell');
        $parent = $this->givenProduct('parent', [$other]);
        $this->givenProduct('child-a', []);

        $this->productRepository->expects($this->once())->method('save')->with($parent);
        $this->givenLinkFactoryYieldsLinks();

        $result = $this->execute(['relation' => ['parent' => ['child-a']]]);

        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeUpdatesProductWithExistingLinks(): void
    {
        $existing = $this->createMock(ProductLinkInterface::class);
        $existing->method('getLinkType')->willReturn('upsell');
        $parent = $this->givenProduct('parent', [$existing]);
        $this->givenProduct('child-a', []);

        // Maintain mode overwrites the existing links of that type.
        $parent->expects($this->once())->method('setProductLinks');
        $this->productRepository->expects($this->once())->method('save')->with($parent);
        $this->givenLinkFactoryYieldsLinks();

        $result = $this->execute(
            ['up_sell' => ['parent' => ['child-a']]],
            false,
            null,
            ComponentMode::Maintain
        );

        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testDryRunDoesNotPersistButRecordsIntent(): void
    {
        $this->givenProduct('parent', []);
        $this->givenProduct('child-a', []);

        $this->productRepository->expects($this->never())->method('save');
        $this->givenLinkFactoryYieldsLinks();

        $result = $this->execute(['cross_sell' => ['parent' => ['child-a']]], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testUnsupportedLinkTypeRecordsError(): void
    {
        $this->productRepository->expects($this->never())->method('save');

        $result = $this->execute(['bogus_type' => ['parent' => ['child-a']]]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testEmptyRootProducesNoWork(): void
    {
        // getData() always yields an array, so the only "no data" shape the
        // component meets in practice is an empty root: it does nothing, cleanly.
        $this->productRepository->expects($this->never())->method('save');

        $result = $this->execute([]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testMissingLinkSkuIsSkippedWithoutSaving(): void
    {
        $this->givenProduct('parent', []);
        // 'ghost' deliberately absent from the registry -> doesProductExist throws.
        $this->productLinkFactory->method('create')->willReturn($this->stubLink());
        $this->productRepository->expects($this->never())->method('save');

        $result = $this->execute(['relation' => ['parent' => ['ghost']]]);

        // The link SKU does not exist: the product loop aborts before save and the
        // run is still considered successful (errors are logged, not collected here).
        $this->assertSame(0, $result->getUpdated());
        $this->assertTrue($result->isSuccessful());
    }

    /**
     * @param array $data value of the product-links root node (link type => sku => [linkSkus])
     */
    private function execute(
        array $data,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $payload = $rawData ?? $data;
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $payload);

        return $this->component->execute($context);
    }

    /**
     * Register a product in the get()-backed registry.
     *
     * @param ProductLinkInterface[] $links
     */
    private function givenProduct(string $sku, array $links): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(123);
        $product->method('getProductLinks')->willReturn($links);

        $this->products[$sku] = $product;

        return $product;
    }

    private function givenLinkFactoryYieldsLinks(): void
    {
        // A fresh chainable link stub per create() call.
        $this->productLinkFactory->method('create')->willReturnCallback(fn (): ProductLinkInterface => $this->stubLink());
    }

    private function stubLink(): ProductLinkInterface&MockObject
    {
        $link = $this->createMock(ProductLinkInterface::class);
        $link->method('setSku')->willReturnSelf();
        $link->method('setLinkedProductSku')->willReturnSelf();
        $link->method('setLinkType')->willReturnSelf();
        $link->method('setPosition')->willReturnSelf();

        return $link;
    }
}
