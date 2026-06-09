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
use Magento\UrlRewrite\Model\UrlRewriteFactory;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Rewrites implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'rewrites';
    private const DESCRIPTION = 'Component to create URL Store Rewrites';

    const THE_ROW_DATA_IS_NOT_VALID_MESSAGE = "The row data is not valid.";
    const URL_REWRITES_COMPLETE_MESSAGE = 'URL Rewrites Complete';
    const URL_REWRITE_REQUIRES_A_REQUEST_PATH_TO_BE_SET_MESSAGE = 'URL Rewrite requires a request path to be set';
    const REQUEST_PATH_CSV_KEY = 'requestPath';
    const REQUEST_PATH_KEY = 'request_path';
    const STORE_ID_CSV_KEY = 'storeId';
    const TARGET_PATH_CSV_KEY = 'targetPath';
    const REDIRECT_TYPE_CSV_KEY = 'redirectType';
    const DESCRIPTION_CSV_KEY = 'description';
    const VERSION_CSV_KEY = 'version';
    const REMOVE_CSV_KEY = 'remove';

    public function __construct(
        private readonly UrlPersistInterface $urlPersist,
        private readonly UrlRewriteFactory $urlRewriteFactory,
        private readonly UrlRewriteResource $urlRewriteResource,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data[0])) {
            $result->addError(self::THE_ROW_DATA_IS_NOT_VALID_MESSAGE);
            return $result;
        }

        $headerRowAttributes = $this->getAttributesFromHeaderRow($data);

        $this->removeHeaderRow($data);

        foreach ($data as $rewriteDataCsvRow) {
            $rewriteArray = [];

            $rewriteArray = $this->extractCsvDataIntoArray(
                $headerRowAttributes,
                $rewriteDataCsvRow,
                $rewriteArray
            );

            try {
                if (!isset($rewriteArray[self::REQUEST_PATH_CSV_KEY])) {
                    $this->log->logError(
                        self::URL_REWRITE_REQUIRES_A_REQUEST_PATH_TO_BE_SET_MESSAGE
                    );
                    continue;
                }

                $this->createOrUpdateRewriteRule($rewriteArray, $context->getMode(), $context->isDryRun(), $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        $this->log->logInfo(
            self::URL_REWRITES_COMPLETE_MESSAGE
        );

        return $result;
    }

    /**
     * Gets the first row of the CSV file as these should be the attribute keys
     *
     * @param array $data
     * @return array
     */
    public function getAttributesFromHeaderRow(array $data): array
    {
        $this->checkHeaderRowExists($data);
        $attributes = [];
        foreach ($data[0] as $attributeCode) {
            $attributes[] = $attributeCode;
        }
        return $attributes;
    }

    /**
     * @param array $data
     * @throws ComponentException
     */
    public function checkHeaderRowExists(array $data): void
    {
        if (!isset($data[0])) {
            throw new ComponentException(
                (string) __(self::THE_ROW_DATA_IS_NOT_VALID_MESSAGE)
            );
        }
    }

    /**
     * @param array $data
     */
    private function removeHeaderRow(array &$data): void
    {
        unset($data[0]);
    }

    /**
     * Creates UrlRedirect from Array
     *
     * @param array $rewriteArray
     */
    public function createOrUpdateRewriteRule(
        array $rewriteArray,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $rewrite = $this->urlRewriteFactory->create();
        $successMessage = 'URL Rewrite: "%s" created';
        $isUpdate = false;
        $collection = $rewrite->getCollection()
            ->addFieldToFilter(self::REQUEST_PATH_KEY, $rewriteArray[self::REQUEST_PATH_CSV_KEY])
            ->addFieldToFilter('store_id', $rewriteArray[self::STORE_ID_CSV_KEY]);
        $rewriteCount = $collection->getSize();

        // Explicit removal: a `remove: true` row deletes the rewrite if it exists,
        // in either mode. Idempotent — a rewrite already absent is skipped.
        if (!empty($rewriteArray[self::REMOVE_CSV_KEY])) {
            $existing = $rewriteCount > 0 ? $collection->getFirstItem() : null;
            $this->removeRewrite(
                $rewriteArray[self::REQUEST_PATH_CSV_KEY],
                $existing,
                $dryRun,
                $result
            );
            return;
        }

        $version = isset($rewriteArray[self::VERSION_CSV_KEY]) && $rewriteArray[self::VERSION_CSV_KEY] !== ''
            ? (int) $rewriteArray[self::VERSION_CSV_KEY]
            : null;

        $request = new ReconciliationRequest(
            self::ALIAS,
            $rewriteArray[self::REQUEST_PATH_CSV_KEY] . '_' . $rewriteArray[self::STORE_ID_CSV_KEY],
            $mode,
            $rewriteCount > 0,
            $version
        );

        if ($this->gate->decide($request)->isSkip()) {
            $this->log->logComment(
                sprintf('URL Rewrite "%s" exists, skipped (create mode)', $rewriteArray[self::REQUEST_PATH_CSV_KEY])
            );
            $result->recordSkipped();
            return;
        }

        if ($rewriteCount > 0) {
            $rewrite = $rewrite->getCollection()
                ->addFieldToFilter(self::REQUEST_PATH_KEY, $rewriteArray[self::REQUEST_PATH_CSV_KEY])
                ->addFieldToFilter('store_id', $rewriteArray[self::STORE_ID_CSV_KEY])
                ->getFirstItem();

            $successMessage = 'URL Rewrite: "%s" already exists, rewrite updated';
            $isUpdate = true;
        }

        if ($dryRun) {
            $this->log->logInfo(
                sprintf(
                    '[dry-run] Would %s URL Rewrite: "%s"',
                    $isUpdate ? 'update' : 'create',
                    $rewriteArray[self::DESCRIPTION_CSV_KEY]
                )
            );
            $isUpdate ? $result->recordUpdated() : $result->recordCreated();
            $this->gate->commitVersion($request, true);
            return;
        }

        $rewrite->setIsAutogenerated(0)
            ->setStoreId($rewriteArray[self::STORE_ID_CSV_KEY])
            ->setRequestPath($rewriteArray[self::REQUEST_PATH_CSV_KEY])
            ->setTargetPath($rewriteArray[self::TARGET_PATH_CSV_KEY])
            ->setRedirectType($rewriteArray[self::REDIRECT_TYPE_CSV_KEY]) //301 or 302
            ->setDescription($rewriteArray[self::DESCRIPTION_CSV_KEY]);
        $this->urlRewriteResource->save($rewrite);

        $this->log->logInfo(
            sprintf($successMessage, $rewriteArray[self::DESCRIPTION_CSV_KEY])
        );

        $isUpdate ? $result->recordUpdated() : $result->recordCreated();
        $this->gate->commitVersion($request, false);
    }

    /**
     * Delete a URL rewrite flagged with `remove: true`. Idempotent: a rewrite that
     * is already absent records a skip rather than an error. Honors dry-run.
     *
     * @param string $requestPath
     * @param \Magento\UrlRewrite\Model\UrlRewrite|null $rewrite
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     */
    protected function removeRewrite(
        string $requestPath,
        $rewrite,
        bool $dryRun,
        ComponentResult $result
    ): void {
        if ($rewrite === null) {
            $this->log->logComment(
                sprintf("URL Rewrite '%s' not present, nothing to remove", $requestPath)
            );
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove URL Rewrite %s', $requestPath));
        } else {
            $this->urlRewriteResource->delete($rewrite);
            $this->log->logInfo(sprintf('Removed URL Rewrite %s', $requestPath));
        }

        $result->recordRemoved();
    }

    /**
     * @param array $attributeKeys
     * @param array $rewriteDataCsvRow
     * @param array $rewriteArray
     * @return array
     */
    public function extractCsvDataIntoArray(
        array $attributeKeys,
        array $rewriteDataCsvRow,
        array $rewriteArray
    ): array {
        foreach ($attributeKeys as $column => $code) {
            $rewriteArray[$code] = $rewriteDataCsvRow[$column];
        }
        return $rewriteArray;
    }

    /**
     * Export non-autogenerated URL rewrites into the source CSV format (a
     * list-of-rows with a header row first). Refresh mode rewrites only the
     * rows already tracked in the source file, keyed on requestPath + storeId,
     * refreshing each from its current DB record (rows no longer in the DB are
     * kept unchanged). Full mode dumps every non-autogenerated rewrite,
     * optionally filtered by a requestPath prefix.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * The CSV column order, matching the documented source format.
     *
     * @return string[]
     */
    private function exportHeader(): array
    {
        return [
            self::REQUEST_PATH_CSV_KEY,
            self::TARGET_PATH_CSV_KEY,
            self::REDIRECT_TYPE_CSV_KEY,
            self::STORE_ID_CSV_KEY,
            self::DESCRIPTION_CSV_KEY,
        ];
    }

    /**
     * Build a CSV data row (in header order) from a loaded rewrite record.
     *
     * @param \Magento\UrlRewrite\Model\UrlRewrite $rewrite
     * @return array
     */
    private function rowFromRewrite($rewrite): array
    {
        return [
            (string) $rewrite->getRequestPath(),
            (string) $rewrite->getTargetPath(),
            (string) $rewrite->getRedirectType(),
            (string) $rewrite->getStoreId(),
            (string) $rewrite->getDescription(),
        ];
    }

    /**
     * Refresh only the rows already tracked in the source CSV. The first row is
     * preserved as the header; each subsequent row is re-read from the DB by its
     * requestPath + storeId. Rows whose rewrite no longer exists are kept as-is.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        if (!isset($existing[0])) {
            return $existing;
        }

        $header = array_values($existing[0]);
        $requestIndex = array_search(self::REQUEST_PATH_CSV_KEY, $header, true);
        $storeIndex = array_search(self::STORE_ID_CSV_KEY, $header, true);

        $out = [$header];

        foreach ($existing as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $row = array_values($row);

            if ($requestIndex === false || $storeIndex === false
                || !isset($row[$requestIndex], $row[$storeIndex])
            ) {
                $out[] = $row;
                continue;
            }

            $current = $this->loadRewrite((string) $row[$requestIndex], (string) $row[$storeIndex]);
            if ($current === null) {
                $out[] = $row;
                continue;
            }

            $refreshed = $this->rowFromRewrite($current);
            $out[] = $this->mapRowToHeader($header, $refreshed);
        }

        return $out;
    }

    /**
     * Re-order a refreshed row (which is in the canonical header order) to match
     * the existing file's header column order, so the file round-trips with its
     * original column layout intact.
     *
     * @param array $header
     * @param array $refreshed
     * @return array
     */
    private function mapRowToHeader(array $header, array $refreshed): array
    {
        $canonical = $this->exportHeader();
        $byKey = [];
        foreach ($canonical as $position => $key) {
            $byKey[$key] = $refreshed[$position] ?? '';
        }

        $row = [];
        foreach ($header as $key) {
            $row[] = $byKey[$key] ?? '';
        }

        return $row;
    }

    /**
     * Dump every non-autogenerated URL rewrite, optionally restricted to a
     * requestPath prefix via the filter.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $collection = $this->urlRewriteFactory->create()->getCollection()
            ->addFieldToFilter('is_autogenerated', 0);

        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter(self::REQUEST_PATH_KEY, ['like' => $filter . '%']);
        }

        $out = [$this->exportHeader()];
        foreach ($collection as $rewrite) {
            $out[] = $this->rowFromRewrite($rewrite);
        }

        return $out;
    }

    /**
     * Load a single non-autogenerated rewrite by request path + store id.
     *
     * @param string $requestPath
     * @param string $storeId
     * @return \Magento\UrlRewrite\Model\UrlRewrite|null
     */
    private function loadRewrite(string $requestPath, string $storeId)
    {
        $collection = $this->urlRewriteFactory->create()->getCollection()
            ->addFieldToFilter('is_autogenerated', 0)
            ->addFieldToFilter(self::REQUEST_PATH_KEY, $requestPath)
            ->addFieldToFilter('store_id', $storeId);

        $rewrite = $collection->getFirstItem();

        return $rewrite->getId() ? $rewrite : null;
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
