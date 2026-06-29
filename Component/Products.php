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
use Magebit\Configurator\Api\ComponentMode;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Component\Product\Image;
use Magebit\Configurator\Component\Product\AttributeOption;
use Magebit\Configurator\Model\Import\ImporterFactory;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Component\Product\ValidatorFactory;
use Magebit\Configurator\Component\Product\Validator;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Products implements ComponentInterface
{
    public const SKU_COLUMN_HEADING = 'sku';
    public const QTY_COLUMN_HEADING = 'qty';
    public const IS_IN_STOCK_COLUMN_HEADING = 'is_in_stock';
    /** Optional CSV column: "source_code=qty[:status];other=qty" (status 1=in stock default). */
    public const MSI_SOURCES_COLUMN = 'msi_sources';
    public const SEPARATOR = ';';

    private const ALIAS = 'products';
    private const DESCRIPTION = 'Component to import products using a CSV file.';

    /** @var string[] */
    protected array $imageAttributes = [
        'image',
        'small_image',
        'thumbnail',
        'media_image',
        'additional_images'
    ];

    /**
     * The attributes that may use ',' as the separator and need replacing
     *
     * @var string[]
     */
    protected array $attrSeparator = [
        'product_websites',
        'store_view_code'
    ];

    /**
     * Attributes that may have newlines defined. These will be split into
     * paragraphs so text looks the same on frontend.
     *
     * @var string[]
     */
    protected array $attrDescription = [
        'description',
        'short_description'
    ];

    /** @var array */
    private array $successProducts = [];

    /** @var array */
    private array $skippedProducts = [];

    /** @var array<string, string> sku => raw msi_sources spec */
    private array $msiSourceItems = [];

    /** @var int|false */
    private $skuColumn;

    public function __construct(
        private readonly ImporterFactory $importerFactory,
        private readonly ProductFactory $productFactory,
        private readonly Image $image,
        private readonly ValidatorFactory $validatorFactory,
        private readonly AttributeOption $attributeOption,
        private readonly LoggerInterface $log,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly SourceItemsSaveInterface $sourceItemsSave
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        // Get the first row of the CSV file for the attribute columns.
        if (!isset($data[0])) {
            $result->addError('The row data is not valid.');
            return $result;
        }
        $attributeKeys = $this->getAttributesFromCsv($data);
        $this->image->setSeparator(self::SEPARATOR);
        $this->skuColumn = $this->getSkuColumnIndex($attributeKeys);
        $totalColumnCount = count($attributeKeys);
        unset($data[0]);

        // Prepare the data
        $productsArray = [];

        foreach ($data as $product) {
            if (count($product) !== $totalColumnCount) {
                $this->skippedProducts[] = $product[$this->skuColumn];
                continue;
            }
            $productArray = [];
            foreach ($attributeKeys as $column => $code) {
                $product[$column] = $this->clean($product[$column], $code);
                if (in_array($code, $this->imageAttributes)) {
                    $product[$column] = $this->image->getImage($product[$column]);
                }
                $productArray[$code] = $product[$column];
                $this->attributeOption->processAttributeValues($code, $productArray[$code]);
            }
            if ($this->isConfigurable($productArray)) {
                $variations = $this->constructConfigurableVariations($productArray);
                if (strlen($variations) === 0) {
                    $this->skippedProducts[] = $product[$this->skuColumn];
                    continue;
                }
                $productArray['configurable_variations'] = $variations;
                unset($productArray['associated_products']);
                unset($productArray['configurable_attributes']);
            }
            if ($this->isStockSpecified($productArray) === false) {
                $productArray = $this->setStock($productArray);
            }
            // Capture MSI source items (if any) and strip the column so
            // the importer doesn't choke on the unknown attribute.
            if (isset($productArray[self::MSI_SOURCES_COLUMN])) {
                $msiSku = (string) ($productArray[self::SKU_COLUMN_HEADING] ?? '');
                if ($msiSku !== '' && (string) $productArray[self::MSI_SOURCES_COLUMN] !== '') {
                    // Keyed by the SKU's original case so source_item.sku matches the product.
                    $this->msiSourceItems[$msiSku] = (string) $productArray[self::MSI_SOURCES_COLUMN];
                }
                unset($productArray[self::MSI_SOURCES_COLUMN]);
            }
            $productsArray[] = $productArray;
            $this->successProducts[] = $product[$this->skuColumn];
        }
        if (count($this->skippedProducts) > 0) {
            $this->log->logInfo(
                sprintf(
                    'The following products were skipped as they do not have the required columns: ' . PHP_EOL . '%s',
                    implode(PHP_EOL, $this->skippedProducts)
                )
            );
        }

        // Row-level reconciliation: in create mode, drop rows whose SKU already
        // exists so we don't re-import existing products. A component-level
        // version bump (the Processor already let us run past its source gate)
        // forces a full re-import. Maintain always re-imports (the import
        // framework is opaque, so we cannot cheaply diff individual attributes).
        if ($context->getMode() === ComponentMode::Create
            && $context->getVersion() === null
            && $productsArray !== []
        ) {
            $existing = $this->loadExistingSkus($this->successProducts);
            if ($existing !== []) {
                $kept = [];
                foreach ($productsArray as $row) {
                    $sku = strtolower((string) ($row[self::SKU_COLUMN_HEADING] ?? ''));
                    if ($sku !== '' && isset($existing[$sku])) {
                        $result->recordSkipped();
                        continue;
                    }
                    $kept[] = $row;
                }
                if (count($kept) !== count($productsArray)) {
                    $this->log->logInfo(sprintf(
                        'Create mode: %d existing product row(s) skipped.',
                        count($productsArray) - count($kept)
                    ));
                }
                $productsArray = $kept;
            }
        }

        if ($productsArray === []) {
            // Nothing survived preparation (e.g. configurable products whose
            // associated simple products don't exist yet). The import source
            // adapter cannot iterate an empty set, so stop here.
            $this->log->logInfo('No products to import after preparation; all rows were skipped.');
            $result->recordSkipped(count($this->skippedProducts));
            return $result;
        }

        $this->attributeOption->saveOptions();
        $this->log->logInfo('Validating import...');
        $validatorImport = $this->importerFactory->create();
        $validatorImport->setMultipleValueSeparator(self::SEPARATOR);
        /**
         * @var Validator $validatorModel
         */
        $validatorModel = $this->validatorFactory->create();
        $validatedProducts = $validatorModel->getValidatedImport($validatorImport, $productsArray);
        $this->log->logInfo(sprintf('Removed %s products after validation.', count($validatorModel->getRemovedRows())));
        $this->log->logInfo(sprintf('Attempting to import %s rows', count($validatedProducts)));

        if ($context->isDryRun()) {
            $this->log->logInfo(
                sprintf('[dry-run] Would import %s product rows via the import framework.', count($validatedProducts))
            );
            if ($this->msiSourceItems !== []) {
                $this->log->logInfo(
                    sprintf('[dry-run] Would apply MSI source items for %d SKU(s).', count($this->msiSourceItems))
                );
            }
            return $result;
        }

        if ($validatedProducts === []) {
            $this->log->logInfo('No valid products remained after validation; nothing to import.');
            return $result;
        }

        try {
            $import = $this->importerFactory->create();
            $import->setMultipleValueSeparator(self::SEPARATOR);
            $import->processImport($validatedProducts);
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
        }
        $this->log->logInfo($import->getLogTrace());
        $this->log->logError($import->getErrorMessages());

        // Apply MSI source items for the SKUs that were actually imported.
        if ($this->msiSourceItems !== []) {
            $importedSkus = [];
            foreach ($validatedProducts as $row) {
                $importedSkus[strtolower((string) ($row[self::SKU_COLUMN_HEADING] ?? ''))] = true;
            }
            $this->applyMsiSourceItems($importedSkus);
        }

        return $result;
    }

    /**
     * Apply captured MSI source items via the inventory API, for the given
     * (lowercased) set of imported SKUs. Spec per SKU: "code=qty[:status]"
     * entries separated by ';'; status 1 (in stock) is the default.
     *
     * @param array<string, true> $importedSkus
     */
    private function applyMsiSourceItems(array $importedSkus): void
    {
        $sourceItems = [];
        foreach ($this->msiSourceItems as $sku => $spec) {
            if (!isset($importedSkus[strtolower($sku)])) {
                continue;
            }
            foreach (explode(self::SEPARATOR, $spec) as $entry) {
                $entry = trim($entry);
                if ($entry === '' || !str_contains($entry, '=')) {
                    continue;
                }
                [$code, $rest] = array_pad(explode('=', $entry, 2), 2, '');
                $code = trim($code);
                if ($code === '') {
                    continue;
                }
                [$qty, $status] = array_pad(explode(':', trim($rest), 2), 2, null);
                $sourceItem = $this->sourceItemFactory->create();
                $sourceItem->setSku($sku);
                $sourceItem->setSourceCode($code);
                $sourceItem->setQuantity((float) $qty);
                $sourceItem->setStatus(
                    $status === null ? SourceItemInterface::STATUS_IN_STOCK : (int) $status
                );
                $sourceItems[] = $sourceItem;
            }
        }

        if ($sourceItems === []) {
            return;
        }

        try {
            $this->sourceItemsSave->execute($sourceItems);
            $this->log->logInfo(sprintf('Applied %d MSI source item(s).', count($sourceItems)));
        } catch (\Exception $e) {
            $this->log->logError(sprintf('Failed to apply MSI source items: %s', $e->getMessage()));
        }
    }

    /**
     * Load the subset of the given SKUs that already exist, as a lowercased
     * lookup set, in a single query. SKU matching is case-insensitive.
     *
     * @param string[] $skus
     * @return array<string, true>
     */
    private function loadExistingSkus(array $skus): array
    {
        $skus = array_values(array_filter(array_map('strval', $skus), static fn (string $s): bool => $s !== ''));
        if ($skus === []) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(self::SKU_COLUMN_HEADING);
        $collection->addFieldToFilter(self::SKU_COLUMN_HEADING, ['in' => $skus]);

        $existing = [];
        foreach ($collection as $product) {
            $existing[strtolower((string) $product->getSku())] = true;
        }

        return $existing;
    }

    /**
     * Gets the file extension
     *
     * @param string $source
     * @return string
     */
    public function getFileType(string $source): string
    {
        // Get the file extension so we know how to load the file
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $sourceFileInfo = pathinfo($source);
        if (!isset($sourceFileInfo['extension'])) {
            throw new ComponentException(
                sprintf('Could not find a valid extension for the source file.')
            );
        }
        $fileType = $sourceFileInfo['extension'];
        return $fileType;
    }

    /**
     * Gets the first row of the CSV file as these should be the attribute keys
     *
     * @param array $data
     * @return array
     */
    public function getAttributesFromCsv(array $data): array
    {
        $attributes = [];
        foreach ($data[0] as $attributeCode) {
            $attributes[] = $attributeCode;
        }
        return $attributes;
    }

    /**
     * Test if a product is a configurable
     *
     * @param array $data
     * @return bool
     */
    public function isConfigurable(array $data = []): bool
    {
        if (isset($data['product_type']) && $data['product_type'] === 'configurable') {
            return true;
        }
        return false;
    }

    /**
     * Create the configurable product string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @param array $data
     * @return string
     */
    public function constructConfigurableVariations(array $data): string
    {
        $variations = '';
        if (isset($data['associated_products']) && isset($data['configurable_attributes'])) {
            $products = explode(',', (string) $data['associated_products']);
            $attributes = explode(',', (string) $data['configurable_attributes']);

            if (is_array($products) && is_array($attributes)) {
                $productsCount = count($products);
                $count = 0;
                foreach ($products as $sku) {
                    if ($count > 0 && $count < $productsCount) {
                        $variations .= '|';
                    }
                    $productModel = $this->productFactory->create();
                    $id = $productModel->getIdBySku($sku);
                    $productModel->load($id);

                    if ($productModel->getId()) {
                        $configSkuAttributes = $this->constructAttributeData($attributes, $productModel);
                        if (strlen($configSkuAttributes) > 0) {
                            $variations .= 'sku=' . $sku . self::SEPARATOR . $configSkuAttributes;
                        }
                        $count++;
                    }
                }
            }
        }
        return $variations;
    }

    /**
     * Get the attributes and the values as a string
     *
     * @param array $attributes
     * @param \Magento\Catalog\Model\Product $productModel
     * @return string
     */
    public function constructAttributeData(array $attributes, \Magento\Catalog\Model\Product $productModel): string
    {
        $skuAttributes = '';
        $attrCounter = 0;
        foreach ($attributes as $attributeCode) {
            $attrCounter++;
            if ($productModel->hasData($attributeCode) == false) {
                $this->log->logError(
                    sprintf(
                        'The product "%s" is missing an attribute value for "%s" and will not be added ' .
                        'to the configurable product',
                        $productModel->getSku(),
                        $attributeCode
                    )
                );
                // Unset any previous attributes.
                $skuAttributes = '';
                break;
            }
            $productAttribute = $productModel->getResource()->getAttribute($attributeCode);
            if ($productAttribute !== false) {
                if ($attrCounter > 1) {
                    $skuAttributes .= self::SEPARATOR;
                }
                $value = $productAttribute->getFrontend()->getValue($productModel);
                $skuAttributes .= $attributeCode . '=' . $value;
            }
        }
        return $skuAttributes;
    }

    /**
     * Tests to see if the stock values have been set
     *
     * @param array $productData
     *
     * @return bool
     */
    public function isStockSpecified(array $productData): bool
    {
        if (isset($productData[self::IS_IN_STOCK_COLUMN_HEADING]) && isset($productData[self::QTY_COLUMN_HEADING])) {
            return true;
        }
        return false;
    }

    /**
     * Set the stock values
     *
     * @param array $productData
     *
     * @return array
     */
    public function setStock(array $productData): array
    {
        $newProductData = $productData;
        if (isset($productData[self::IS_IN_STOCK_COLUMN_HEADING]) &&
            $productData[self::IS_IN_STOCK_COLUMN_HEADING] == 1 &&
            isset($productData[self::QTY_COLUMN_HEADING]) == false) {
            $newProductData[self::QTY_COLUMN_HEADING] = 1;
        }
        return $newProductData;
    }

    /**
     * Replace the separator ','
     *
     * @param $data
     * @param string $column
     *
     * @return mixed
     */
    private function replaceSeparator($data, string $column)
    {
        if (in_array($column, $this->attrSeparator)) {
            return str_replace(',', self::SEPARATOR, (string) $data);
        }
        return $data;
    }

    /**
     * Format description attribute values where newlines indicate
     * the position of paragraphs.
     *
     * @param $data
     * @param string $column
     *
     * @return mixed|string
     */
    private function insertParagraphs($data, string $column)
    {
        if (in_array($column, $this->attrDescription) && !$this->spotHtmlTags($data, "p")) {
            $data = str_replace(PHP_EOL, "</p>".PHP_EOL."<p>", (string) $data);
            $data = str_replace("<p></p>".PHP_EOL, "", $data);
            $data = "<p>".$data."</p>";
        }
        return $data;
    }

    /**
     * Find html tags in the given string
     *
     * @param $string
     * @param string $tagname
     *
     * @return int
     */
    private function spotHtmlTags($string, string $tagname): int
    {
        $matches = [];
        $pattern = "/<$tagname?.*>(.*)<\/$tagname>/";
        preg_match($pattern, (string) $string, $matches);
        return count($matches);
    }

    /**
     * Tidy up the value
     *
     * @param $value
     * @param string $column
     *
     * @return string
     */
    private function clean($value, string $column): string
    {
        $value = $this->replaceSeparator($value, $column);
        $value = $this->insertParagraphs($value, $column);
        return trim((string) $value);
    }

    /**
     * Get the column index of the SKU
     *
     * @param array $headers
     *
     * @return int|false
     */
    public function getSkuColumnIndex(array $headers)
    {
        return array_search(self::SKU_COLUMN_HEADING, $headers);
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
