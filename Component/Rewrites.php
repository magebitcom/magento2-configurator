<?php

namespace CtiDigital\Configurator\Component;

use CtiDigital\Configurator\Api\ComponentInterface;
use CtiDigital\Configurator\Exception\ComponentException;
use CtiDigital\Configurator\Api\LoggerInterface;
use Magento\UrlRewrite\Model\UrlRewriteFactory;
use Magento\UrlRewrite\Model\UrlPersistInterface;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Rewrites implements ComponentInterface
{
    protected $alias = "rewrites";
    protected $name = "rewrites";
    protected $description = "Component to create URL Store Rewrites";
    const THE_ROW_DATA_IS_NOT_VALID_MESSAGE = "The row data is not valid.";
    const URL_REWRITES_COMPLETE_MESSAGE = 'URL Rewrites Complete';
    const URL_REWRITE_REQUIRES_A_REQUEST_PATH_TO_BE_SET_MESSAGE = 'URL Rewrite requires a request path to be set';
    const REQUEST_PATH_CSV_KEY = 'requestPath';
    const REQUEST_PATH_KEY = 'request_path';
    const STORE_ID_CSV_KEY = 'storeId';
    const TARGET_PATH_CSV_KEY = 'targetPath';
    const REDIRECT_TYPE_CSV_KEY = 'redirectType';
    const DESCRIPTION_CSV_KEY = 'description';

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var UrlRewriteFactory
     */
    protected $urlRewriteFactory;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * Rewrites constructor.
     * @param UrlPersistInterface $urlPersist
     * @param UrlRewriteFactory $urlRewriteFactory
     * @param LoggerInterface $log
     */
    public function __construct(
        UrlPersistInterface $urlPersist,
        UrlRewriteFactory $urlRewriteFactory,
        LoggerInterface $log
    ) {
        $this->urlPersist = $urlPersist;
        $this->urlRewriteFactory = $urlRewriteFactory;
        $this->log = $log;
    }

    /**
     * @param array|null $data
     */
    public function execute($data = null)
    {
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

                $this->createOrUpdateRewriteRule($rewriteArray);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
            }
        }

        $this->log->logInfo(
            self::URL_REWRITES_COMPLETE_MESSAGE
        );
    }

    /**
     * Gets the first row of the CSV file as these should be the attribute keys
     *
     * @param null $data
     * @return array
     */
    public function getAttributesFromHeaderRow($data = null)
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
     * @return array
     */
    public function checkHeaderRowExists(array $data)
    {
        if (!isset($data[0])) {
            throw new ComponentException(
                self::THE_ROW_DATA_IS_NOT_VALID_MESSAGE
            );
        }
    }

    /**
     * @param array $data
     */
    private function removeHeaderRow(array &$data)
    {
        unset($data[0]);
    }

    /**
     * Creates UrlRedirect from Array
     *
     * @param $rewriteArray
     */
    public function createOrUpdateRewriteRule(array $rewriteArray)
    {
        $rewrite = $this->urlRewriteFactory->create();
        $successMessage = 'URL Rewrite: "%s" created';
        $rewriteCount = $rewrite->getCollection()
            ->addFieldToFilter(self::REQUEST_PATH_KEY, $rewriteArray[self::REQUEST_PATH_CSV_KEY])
            ->addFieldToFilter('store_id', $rewriteArray[self::STORE_ID_CSV_KEY])
            ->getSize();

        if ($rewriteCount > 0) {
            $rewrite = $rewrite->getCollection()
                ->addFieldToFilter(self::REQUEST_PATH_KEY, $rewriteArray[self::REQUEST_PATH_CSV_KEY])
                ->addFieldToFilter('store_id', $rewriteArray[self::STORE_ID_CSV_KEY])
                ->getFirstItem();

            $successMessage = 'URL Rewrite: "%s" already exists, rewrite updated';
        }

        $rewrite->setIsAutogenerated(0)
            ->setStoreId($rewriteArray[self::STORE_ID_CSV_KEY])
            ->setRequestPath($rewriteArray[self::REQUEST_PATH_CSV_KEY])
            ->setTargetPath($rewriteArray[self::TARGET_PATH_CSV_KEY])
            ->setRedirectType($rewriteArray[self::REDIRECT_TYPE_CSV_KEY]) //301 or 302
            ->setDescription($rewriteArray[self::DESCRIPTION_CSV_KEY])
            ->save();

        $this->log->logInfo(
            sprintf($successMessage, $rewriteArray[self::DESCRIPTION_CSV_KEY])
        );
    }

    /**
     * @param $attributeKeys
     * @param $rewriteDataCsvRow
     * @param $rewriteArray
     * @return mixed
     */
    public function extractCsvDataIntoArray($attributeKeys, $rewriteDataCsvRow, $rewriteArray)
    {
        foreach ($attributeKeys as $column => $code) {
            $rewriteArray[$code] = $rewriteDataCsvRow[$column];
        }
        return $rewriteArray;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }
}
