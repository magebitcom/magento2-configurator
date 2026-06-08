<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;

class ProductLinks implements ComponentInterface
{
    private const ALIAS = 'product_links';
    private const DESCRIPTION = 'Component to create and maintain product links (related/up-sells/cross-sells)';

    /** @var string[] */
    protected array $allowedLinks = ['relation', 'up_sell', 'cross_sell'];

    /** @var array<string, string> */
    protected array $linkTypeMap = ['relation' => 'related', 'up_sell' => 'upsell', 'cross_sell' => 'crosssell'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductLinkInterfaceFactory $productLinkFactory,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * Process the data by splitting up the different link types.
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!is_array($data)) {
            $result->addError('No product link data found in the source data.');
            return $result;
        }

        try {
            // Loop through all the product link types - if there are multiple link types in the yaml file
            foreach ($data as $linkType => $skus) {
                // Validate the link type to see if it is allowed
                if (!in_array($linkType, $this->allowedLinks)) {
                    throw new ComponentException((string) __('Link type %1 is not supported', $linkType));
                }

                // Process creating the links
                $this->processSkus($skus, (string) $linkType, $context, $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
        }

        return $result;
    }

    /**
     * Process an array of products that require products linking to them
     *
     * @param array $data
     * @param string $linkType
     * @param ComponentContext $context
     * @param ComponentResult $result
     */
    private function processSkus(
        array $data,
        string $linkType,
        ComponentContext $context,
        ComponentResult $result
    ): void {
        try {
            // Loop through the SKUs in the link type
            foreach ($data as $sku => $linkSkus) {
                // Check if the product exists
                if (!$this->doesProductExist((string) $sku)) {
                    throw new ComponentException((string) __('SKU (%1) for products to link to is not found', $sku));
                }
                $this->log->logInfo(sprintf('Creating product links for %s', $sku));

                // Process the links for that product
                $this->processLinks((string) $sku, $linkSkus, $linkType, $context, $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
        }
    }

    /**
     * Process all the SKUs that need to be linked to a particular product (SKU)
     *
     * @param string $sku
     * @param array $linkSkus
     * @param string $linkType
     * @param ComponentContext $context
     * @param ComponentResult $result
     */
    private function processLinks(
        string $sku,
        array $linkSkus,
        string $linkType,
        ComponentContext $context,
        ComponentResult $result
    ): void {
        try {
            $productLinks = [];

            // Loop through all the products that require linking to a product
            foreach ($linkSkus as $position => $linkSku) {
                // Check if the product exists
                if (!$this->doesProductExist((string) $linkSku)) {
                    throw new ComponentException((string) __('SKU (%1) to link does not exist', $linkSku));
                }

                // Create an array of product link objects
                $productLinks[] = $this->productLinkFactory->create()->setSku($sku)
                    ->setLinkedProductSku($linkSku)
                    ->setLinkType($this->linkTypeMap[$linkType])
                    ->setPosition($position * 10);
                $this->log->logInfo($linkSku, 1);
            }

            if ($context->isDryRun()) {
                $this->log->logInfo(sprintf('[dry-run] Would save product links for %s', $sku), 1);
                $result->recordUpdated();

                return;
            }

            // Save product links onto the main product
            $product = $this->productRepository->get($sku);
            $product->setProductLinks($productLinks);
            $this->productRepository->save($product);
            $this->log->logComment(sprintf('Saved product links for %s', $sku), 1);
            $result->recordUpdated();
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage(), 1);
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage(), 1);
        }
    }

    /**
     * Check if the product exists function
     *
     * @param string $sku
     * @return bool
     * @todo find an efficient way to check if the product exists.
     */
    private function doesProductExist(string $sku): bool
    {
        if ($this->productRepository->get($sku)->getId()) {
            return true;
        }
        return false;
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
