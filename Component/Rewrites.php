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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\UrlRewrite\Model\UrlRewriteFactory;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Rewrites implements ComponentInterface
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
        $rewriteCount = $rewrite->getCollection()
            ->addFieldToFilter(self::REQUEST_PATH_KEY, $rewriteArray[self::REQUEST_PATH_CSV_KEY])
            ->addFieldToFilter('store_id', $rewriteArray[self::STORE_ID_CSV_KEY])
            ->getSize();

        $request = new ReconciliationRequest(
            self::ALIAS,
            $rewriteArray[self::REQUEST_PATH_CSV_KEY] . '_' . $rewriteArray[self::STORE_ID_CSV_KEY],
            $mode,
            $rewriteCount > 0
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
