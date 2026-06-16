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
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as AttrOptionCollectionFactory;
use Magento\Swatches\Helper\Data as SwatchHelper;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Attributes implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'attributes';
    private const DESCRIPTION = 'Component to create/maintain attributes.';

    /**
     * @var array
     */
    protected $attributeConfigMap = [
        'label' => 'frontend_label',
        'type' => 'backend_type',
        'input' => 'frontend_input',
        'product_types' => 'apply_to',
        'required' => 'is_required',
        'source' => 'source_model',
        'backend' => 'backend_model',
        'frontend' => 'frontend_model',
        'searchable' => 'is_searchable',
        'global' => 'is_global',
        'filterable_in_search' => 'is_filterable_in_search',
        'unique' => 'is_unique',
        'visible_in_advanced_search' => 'is_visible_in_advanced_search',
        'comparable' => 'is_comparable',
        'visible_on_front' => 'is_visible_on_front',
        'filterable' => 'is_filterable',
        'user_defined' => 'is_user_defined',
        'default' => 'default_value',
        'used_for_promo_rules' => 'is_used_for_promo_rules',
        'wysiwyg_enabled' => 'is_wysiwyg_enabled'
    ];

    /**
     * @var array
     */
    protected $skipCheck = [
        'option',
        'used_in_forms',
        'remove'
    ];

    /**
     * Keys EavSetup::addAttribute() accepts but that are not stored on the row
     * returned by getAttribute() — they live in eav_entity_attribute (the
     * attribute-set/group pivot) or are scope-specific, and which ones are absent
     * depends on the entity type. They cannot be diffed against the loaded
     * attribute, so when absent they must be skipped silently rather than reported
     * as "does not exist or is not mapped". When the entity type does expose one of
     * them on the row, it is still diffed normally.
     *
     * @var array
     */
    protected $addAttributeOnly = [
        'visible',
        'sort_order',
        'group',
        'position',
    ];

    /**
     * @var string
     */
    protected $entityTypeId = Product::ENTITY;

    /**
     * @var bool
     */
    protected $updateAttribute = true;

    /**
     * @var bool
     */
    protected $attributeExists = false;

    /**
     * @var array
     */
    protected $swatchMap = [];

    /**
     * @var array
     */
    protected $optionCollection = [];

    public function __construct(
        protected readonly EavSetup $eavSetup,
        protected readonly AttributeRepositoryInterface $attributeRepository,
        protected readonly LoggerInterface $log,
        protected readonly AttrOptionCollectionFactory $attrOptionCollectionFactory,
        protected readonly EavConfig $eavConfig,
        protected readonly ReconciliationGate $gate,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly SwatchHelper $swatchHelper
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['attributes']) || !is_array($data['attributes'])) {
            $result->addError('No "attributes" node found in the source data.');
            return $result;
        }

        try {
            foreach ($data['attributes'] as $attributeCode => $attributeConfiguration) {
                $this->processAttribute(
                    $attributeCode,
                    $attributeConfiguration,
                    $context->getMode(),
                    $context->isDryRun(),
                    $result
                );
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * @param string $attributeCode
     * @param array $attributeConfig
     */
    protected function processAttribute(
        $attributeCode,
        array $attributeConfig,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $this->updateAttribute = true;
        $this->attributeExists = false;
        $attributeArray = $this->eavSetup->getAttribute($this->entityTypeId, $attributeCode);
        if ($attributeArray && $attributeArray['attribute_id']) {
            $this->attributeExists = true;
        }

        // Explicit removal: `remove: true` deletes the attribute if it exists,
        // in either mode. Idempotent — an attribute already absent is skipped.
        if (!empty($attributeConfig['remove'])) {
            $this->removeAttribute((string) $attributeCode, $attributeArray, $dryRun, $result);
            return;
        }

        if ($this->attributeExists) {
            $this->log->logComment(sprintf('Attribute %s exists. Checking for updates.', $attributeCode));
            $this->updateAttribute = $this->checkForAttributeUpdates($attributeCode, $attributeArray, $attributeConfig);

            if (isset($attributeConfig['option'])) {
                $newAttributeOptions = $this->manageAttributeOptions($attributeCode, $attributeConfig['option']);
                if (!empty($newAttributeOptions)) {
                    $this->updateAttribute = true;
                }
                $attributeConfig['option']['values'] = $newAttributeOptions;
            }
        }

        $version = $attributeConfig['version'] ?? null;
        $request = new ReconciliationRequest(
            $this->getAlias(),
            (string) $attributeCode,
            $mode,
            $this->attributeExists,
            $version ? (int) $version : null,
            $this->attributeExists ? !$this->updateAttribute : null
        );

        if ($this->gate->decide($request)->isSkip()) {
            $this->log->logComment(sprintf('No update for attribute %s (unchanged or create mode).', $attributeCode));
            // An unchanged attribute already matches the declared version; persist it so
            // a later manual edit isn't mistaken for a stale entity and overwritten. The
            // create-mode-protection skip (the attribute differs) deliberately does not.
            if ($this->attributeExists && !$this->updateAttribute) {
                $this->gate->commitVersion($request, $dryRun);
            }
            $result->recordSkipped();
            return;
        }

        // Keep the version/remove markers out of the EAV attribute config.
        unset($attributeConfig['version'], $attributeConfig['remove']);

        if (!array_key_exists('user_defined', $attributeConfig)) {
            $attributeConfig['user_defined'] = 1;
        }

        if (isset($attributeConfig['product_types'])) {
            $attributeConfig['apply_to'] = implode(',', $attributeConfig['product_types']);
        }
        //swatch functionality
        $swatch = false;
        if (in_array($attributeConfig['input'] ?? null, ['swatch_text', 'swatch_visual'], true)) {
            $swatch = $attributeConfig['input'];
            $attributeConfig['input'] = 'select';
            $this->swatchMap = $attributeConfig['swatch'] ?? [];
        }
        //swatch functionality
        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would add/update attribute %s.', $attributeCode));
        } else {
            $this->eavSetup->addAttribute(
                $this->entityTypeId,
                $attributeCode,
                $attributeConfig
            );
        }

        if ($this->attributeExists) {
            $this->log->logInfo(sprintf('Attribute %s updated.', $attributeCode));
            $this->gate->commitVersion($request, $dryRun);
            $result->recordUpdated();
            return;
        }
        //swatch functionality
        if ($swatch) {
            if ($swatch === 'swatch_text') {
                $this->convertToTextSwatch($attributeCode, $attributeConfig, $dryRun);
            } else {
                $this->convertToVisualSwatch($attributeCode, $attributeConfig, $dryRun);
            }
        }
        //swatch functionality

        $this->log->logInfo(sprintf('Attribute %s created.', $attributeCode));
        $this->gate->commitVersion($request, $dryRun);
        $result->recordCreated();
    }

    /**
     * Delete an attribute flagged with `remove: true`. Idempotent: an attribute
     * that is already absent records a skip rather than an error. Only
     * user-defined attributes are removed; system attributes are protected.
     * Honors dry-run.
     *
     * @param string $attributeCode
     * @param array|false $attributeArray
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     */
    protected function removeAttribute(
        string $attributeCode,
        $attributeArray,
        bool $dryRun,
        ComponentResult $result
    ): void {
        if (!$attributeArray || empty($attributeArray['attribute_id'])) {
            $this->log->logComment(sprintf("Attribute '%s' not present, nothing to remove", $attributeCode));
            $result->recordSkipped();
            return;
        }

        if (isset($attributeArray['is_user_defined']) && !$attributeArray['is_user_defined']) {
            $this->log->logComment(sprintf("Attribute '%s' is a system attribute, skipping removal", $attributeCode));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove attribute %s', $attributeCode));
        } else {
            $this->eavSetup->removeAttribute($this->entityTypeId, $attributeCode);
            $this->log->logInfo(sprintf('Removed attribute %s', $attributeCode));
        }

        $result->recordRemoved();
    }

    protected function checkForAttributeUpdates($attributeCode, $attributeArray, $attributeConfig)
    {
        $requiresUpdate = false;
        $nest = 1;
        foreach ($attributeConfig as $name => $value) {
            if ($name == "product_types") {
                $value = implode(',', $value);
            }

            $name = $this->mapAttributeConfig($name);

            if (in_array($name, $this->skipCheck)) {
                continue;
            }
            if (!array_key_exists($name, $attributeArray)) {
                // Known addAttribute-only keys aren't on the loaded row for this
                // entity type; their absence is expected, not a misconfiguration.
                if (!in_array($name, $this->addAttributeOnly, true)) {
                    $this->log->logError(sprintf(
                        'Attribute %s type %s does not exist or is not mapped',
                        $attributeCode,
                        $name
                    ), $nest);
                }
                continue;
            }

            if ($attributeArray[$name] != $value) {
                $this->log->logInfo(sprintf(
                    'Update required for %s as %s is "%s" but should be "%s"',
                    $attributeCode,
                    $name,
                    $attributeArray[$name],
                    $value
                ), $nest);

                $requiresUpdate = true;

                continue;
            }

            $this->log->logComment(sprintf(
                'No Update required for %s as %s is still "%s"',
                $attributeCode,
                $name,
                $value
            ), $nest);
        }

        return $requiresUpdate;
    }

    protected function mapAttributeConfig($name)
    {
        if (isset($this->attributeConfigMap[$name])) {
            return $this->attributeConfigMap[$name];
        }
        return $name;
    }

    private function manageAttributeOptions($attributeCode, $option)
    {
        $attributeOptions = [];
        try {
            $attribute = $this->attributeRepository->get($this->entityTypeId, $attributeCode);
            $attributeOptions = $attribute->getOptions();
        } catch (NoSuchEntityException $e) {
            $this->log->logError(sprintf(
                'Attribute %s doesn\'t exist',
                $attributeCode
            ));
        } catch (\TypeError $e) {
            $this->log->logError(sprintf(
                'Couldn\'t retrieve options for attribute %s.',
                $attributeCode
            ));
        } catch (\BadMethodCallException $e) {
            // @todo This should not happen. Rerunning customer attribute option appear to cause this exception.
            $this->log->logError(sprintf(
                'Couldn\'t retrieve options for attribute %s: %s',
                $attributeCode,
                $e->getMessage()
            ));
            return [];
        }

        // Loop through existing attributes options
        $existingAttributeOptions = [];
        foreach ($attributeOptions as $attributeOption) {
            $value = $attributeOption->getLabel();
            $existingAttributeOptions[] = $value;
        }

        $optionsToAdd = array_diff($option['values'], $existingAttributeOptions);
        //$optionsToRemove = array_diff($existingAttributeOptions, $option['values']);

        return $optionsToAdd;
    }

    /**
     * Export attribute definitions in the source format. Refresh mode rewrites
     * only the keys already tracked per attribute (with current DB values); full
     * mode dumps every user-defined attribute (optionally code-prefix filtered).
     * The top node is the component alias ('attributes' / 'customer_attributes'),
     * so this works unchanged for the CustomerAttributes subclass.
     */
    public function export(ExportContext $context): array
    {
        $node = $this->getAlias();

        if ($context->isFullExport()) {
            return [$node => $this->exportAllAttributes($context->getFilter())];
        }

        $existing = $context->getExistingData();
        $tracked = (isset($existing[$node]) && is_array($existing[$node])) ? $existing[$node] : [];

        $out = [];
        foreach ($tracked as $code => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $attributeArray = $this->eavSetup->getAttribute($this->entityTypeId, (string) $code);
            if (!$attributeArray || empty($attributeArray['attribute_id'])) {
                // Attribute no longer exists; keep the tracked entry untouched.
                $out[$code] = $entry;
                continue;
            }

            $full = $this->buildExportEntry((string) $code, $attributeArray);
            foreach ($entry as $key => $ignored) {
                if ($key === 'option') {
                    if (isset($full['option'])) {
                        $entry['option'] = $full['option'];
                    }
                    continue;
                }
                if (array_key_exists($key, $full)) {
                    $entry[$key] = $full[$key];
                }
            }
            $out[$code] = $entry;
        }

        return [$node => $out];
    }

    /**
     * @param string|null $filter
     * @return array
     */
    private function exportAllAttributes(?string $filter): array
    {
        $this->searchCriteriaBuilder->addFilter('is_user_defined', 1);
        if ($filter !== null && $filter !== '') {
            $this->searchCriteriaBuilder->addFilter('attribute_code', $filter . '%', 'like');
        }

        $out = [];
        try {
            $list = $this->attributeRepository->getList($this->entityTypeId, $this->searchCriteriaBuilder->create());
        } catch (\Exception $e) {
            $this->log->logError(sprintf('Could not list attributes for export: %s', $e->getMessage()));
            return $out;
        }

        foreach ($list->getItems() as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            $attributeArray = $this->eavSetup->getAttribute($this->entityTypeId, $code);
            if (!$attributeArray || empty($attributeArray['attribute_id'])) {
                continue;
            }
            $out[$code] = $this->buildExportEntry($code, $attributeArray);
        }

        return $out;
    }

    /**
     * Build a full source-format entry for one attribute by reversing the
     * friendly-key map, splitting apply_to into product_types, resolving the
     * (swatch-aware) input, and exporting any options.
     *
     * @param string $code
     * @param array $attributeArray
     * @return array
     */
    private function buildExportEntry(string $code, array $attributeArray): array
    {
        $entry = [];
        foreach (array_flip($this->attributeConfigMap) as $eavKey => $yamlKey) {
            if (!array_key_exists($eavKey, $attributeArray)) {
                continue;
            }
            $value = $attributeArray[$eavKey];
            if ($yamlKey === 'product_types') {
                if ($value === null || $value === '') {
                    continue;
                }
                $value = explode(',', (string) $value);
            }
            if ($value === null) {
                continue;
            }
            $entry[$yamlKey] = $value;
        }

        $input = $this->resolveExportInput($code, $attributeArray);
        $entry['input'] = $input;

        $options = $this->exportOptionValues($code, $input);
        if ($options !== null) {
            $entry['option'] = ['values' => $options];
        }

        return $entry;
    }

    /**
     * Resolve the source `input` value: a product attribute backed by a swatch
     * is reported as swatch_visual / swatch_text; otherwise the frontend_input.
     */
    private function resolveExportInput(string $code, array $attributeArray): string
    {
        $frontendInput = (string) ($attributeArray['frontend_input'] ?? 'text');
        if ($this->entityTypeId !== Product::ENTITY) {
            return $frontendInput;
        }

        try {
            $attribute = $this->eavConfig->getAttribute($this->entityTypeId, $code);
            if ($attribute && $attribute->getId() && $this->swatchHelper->isSwatchAttribute($attribute)) {
                return $this->swatchHelper->isVisualSwatch($attribute) ? 'swatch_visual' : 'swatch_text';
            }
        } catch (\Exception $e) {
            // Fall back to the plain frontend input.
        }

        return $frontendInput;
    }

    /**
     * Export an attribute's options: a list of labels for plain selects, or a
     * label => swatch-value map for swatches. Null when the attribute has no
     * options (non select/multiselect).
     *
     * @return array|null
     */
    private function exportOptionValues(string $code, string $input): ?array
    {
        try {
            $attribute = $this->eavConfig->getAttribute($this->entityTypeId, $code);
        } catch (\Exception $e) {
            return null;
        }
        if (!$attribute || !$attribute->getId()
            || !in_array($attribute->getFrontendInput(), ['select', 'multiselect'], true)
        ) {
            return null;
        }

        try {
            $options = $attribute->getOptions() ?: [];
        } catch (\Exception $e) {
            return null;
        }

        $labelsById = [];
        foreach ($options as $option) {
            $value = $option->getValue();
            if ($value === '' || $value === null) {
                // Skip the empty "-- Please Select --" placeholder option.
                continue;
            }
            $labelsById[(int) $value] = (string) $option->getLabel();
        }
        if ($labelsById === []) {
            return null;
        }

        if (in_array($input, ['swatch_visual', 'swatch_text'], true)) {
            $swatches = $this->swatchHelper->getSwatchesByOptionsId(array_keys($labelsById));
            $map = [];
            foreach ($labelsById as $optionId => $label) {
                $map[$label] = $swatches[$optionId]['value'] ?? '';
            }
            return $map;
        }

        return array_values($labelsById);
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }

    /**
     * @param string $attributeName
     * @param array $attributeConfig
     * @return void
     */
    public function convertToVisualSwatch(string $attributeName, array $attributeConfig, bool $dryRun): void
    {
        $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeName);
        if (!$attribute) {
            return;
        }
        $attributeData['option'] = $this->addExistingOptions($attribute);
        $attributeData['frontend_input'] = 'select';
        $attributeData['swatch_input_type'] = 'visual';
        $attributeData['update_product_preview_image'] = 1;
        $attributeData['use_product_image_for_swatch'] = 0;
        $attributeData['optionvisual'] = $this->getOptionSwatch($attributeData, $attributeConfig['option']['values']);
        $attributeData['swatchvisual'] = $this->getOptionSwatchVisual($attributeData);
        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would convert attribute %s to a visual swatch.', $attributeName));
            return;
        }
        $attribute->addData($attributeData);
        $attribute->getResource()->save($attribute);
    }

    /**
     * @param string $attributeName
     * @param array $attributeConfig
     * @return void
     */
    public function convertToTextSwatch(string $attributeName, array $attributeConfig, bool $dryRun): void
    {
        $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeName);
        if (!$attribute) {
            return;
        }
        $attributeData['option'] = $this->addExistingOptions($attribute);
        $attributeData['frontend_input'] = 'select';
        $attributeData['swatch_input_type'] = 'text';
        $attributeData['update_product_preview_image'] = 1;
        $attributeData['use_product_image_for_swatch'] = 0;
        $attributeData['optiontext'] = $this->getOptionSwatch($attributeData, $attributeConfig['option']['values']);
        $attributeData['swatchtext'] = $this->getOptionSwatchText($attributeData);
        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would convert attribute %s to a text swatch.', $attributeName));
            return;
        }
        $attribute->addData($attributeData);
        $attribute->getResource()->save($attribute);
    }


    /**
     * @param array $attributeData
     * @return array
     */
    private function getOptionSwatchVisual(array $attributeData): array
    {
        $optionSwatch = ['value' => []];
        foreach ($attributeData['option'] as $optionKey => $optionValue) {
            if (substr($optionValue, 0, 1) == '#' && strlen($optionValue) == 7) {
                $optionSwatch['value'][$optionKey] = $optionValue;
            } elseif (!empty($this->swatchMap[$optionKey])) {
                $optionSwatch['value'][$optionKey] = $this->swatchMap[$optionKey];
            } else {
                $optionSwatch['value'][$optionKey] = null;
            }
        }
        return $optionSwatch;
    }

    /**
     * @param array $attributeData
     * @param array $attributeOptions
     * @return array
     */
    protected function getOptionSwatch(array $attributeData, array $attributeOptions): array
    {
        $optionSwatch = ['order' => [], 'value' => [], 'delete' => []];
        $i = 0;
        foreach ($attributeData['option'] as $optionKey => $optionValue) {
            $label = array_search($optionValue, $attributeOptions) ?? $optionValue;
            $optionSwatch['delete'][$optionKey] = '';
            $optionSwatch['order'][$optionKey] = (string)$i++;
            $optionSwatch['value'][$optionKey] = [$label, ''];
        }
        return $optionSwatch;
    }

    /**
     * @param array $attributeData
     * @return array
     */
    private function getOptionSwatchText(array $attributeData): array
    {
        $optionSwatch = ['value' => []];
        foreach ($attributeData['option'] as $optionKey => $optionValue) {
            $optionSwatch['value'][$optionKey] = [$optionValue, ''];
        }
        return $optionSwatch;
    }

    /**
     * @param $attributeId
     * @return void
     */
    private function loadOptionCollection($attributeId)
    {
        if (empty($this->optionCollection[$attributeId])) {
            $this->optionCollection[$attributeId] = $this->attrOptionCollectionFactory->create()
                ->setAttributeFilter($attributeId)
                ->setPositionOrder('asc', true)
                ->load();
        }
    }

    /**
     * @param Attribute $attribute
     * @return array
     */
    private function addExistingOptions(Attribute $attribute): array
    {
        $options = [];
        $attributeId = $attribute->getId();
        if ($attributeId) {
            $this->loadOptionCollection($attributeId);
            /** @var \Magento\Eav\Model\Entity\Attribute\Option $option */
            foreach ($this->optionCollection[$attributeId] as $option) {
                $options[$option->getId()] = $option->getValue();
            }
        }

        return $options;
    }
}
