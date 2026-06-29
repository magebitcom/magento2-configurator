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
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Import\ImporterFactory;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\ImportExport\Model\Import;
use Magento\Indexer\Model\IndexerFactory;

class Customers implements ComponentInterface
{
    public const CUSTOMER_EMAIL_HEADER = 'email';
    public const CUSTOMER_GROUP_HEADER = 'group_id';

    private const ALIAS = 'customers';
    private const DESCRIPTION = 'Import customers and addresses';

    /** @var string[] */
    protected array $requiredColumns = [
        'email',
        '_website',
        '_store',
    ];

    /** @var array|null */
    protected ?array $customerGroups = null;

    protected ?int $groupDefault = null;

    /** @var array */
    protected array $columnHeaders = [];

    public function __construct(
        protected readonly ImporterFactory $importerFactory,
        protected readonly GroupRepositoryInterface $groupRepository,
        protected readonly GroupManagementInterface $groupManagement,
        protected readonly SearchCriteriaBuilder $criteriaBuilder,
        protected readonly IndexerFactory $indexerFactory,
        private readonly LoggerInterface $log,
        private readonly CustomerCollectionFactory $customerCollectionFactory
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data[0])) {
            $result->addError('No data has been found in the import file');
            return $result;
        }

        try {
            $this->getColumnHeaders($data);
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
            return $result;
        }

        unset($data[0]);

        $customerImport = [];

        $rowIndex = 0;
        foreach ($data as $customer) {
            $row = [];
            $extraItem = false;
            foreach ($this->getHeaders() as $key => $columnHeader) {
                if (!array_key_exists($key, $customer)) {
                    $this->log->logError(
                        sprintf(
                            'The key "%s" was not found on row "%s".',
                            $key,
                            $rowIndex
                        )
                    );
                    continue;
                }
                $row[$columnHeader] = $customer[$key];

                if ($columnHeader === self::CUSTOMER_EMAIL_HEADER &&
                    strlen((string) $row[self::CUSTOMER_EMAIL_HEADER]) === 0) {
                    // If no email address is specified then it's an extra address being specified.
                    $extraItem = true;
                }

                if ($extraItem === false &&
                    $columnHeader === self::CUSTOMER_GROUP_HEADER &&
                    $this->isValidGroup($row[$columnHeader]) === false
                ) {
                    $this->log->logError(
                        sprintf(
                            'The customer group ID "%s" is not valid for row "%s". Default value set.',
                            $row[$columnHeader],
                            $rowIndex
                        )
                    );
                    $row[self::CUSTOMER_GROUP_HEADER] = $this->getDefaultGroupId();
                }
            }
            $customerImport[] = $row;
            $rowIndex++;
        }

        // Row-level reconciliation: in create mode drop whole customer groups
        // (an email-bearing row plus its trailing address rows) whose email
        // already exists. A version bump forces a full re-import; maintain
        // re-imports (APPEND upserts). Matching is case-insensitive.
        if ($context->getMode() === ComponentMode::Create
            && $context->getVersion() === null
            && $customerImport !== []
        ) {
            $customerImport = $this->dropExistingCustomers($customerImport, $result);
        }

        $importCount = count($customerImport);

        if ($importCount === 0) {
            $this->log->logInfo('No new customers to import (all already exist in create mode).');
            return $result;
        }

        if ($context->isDryRun()) {
            $this->log->logInfo(
                sprintf('[dry-run] Would import %d customer row(s) and reindex the customer grid', $importCount)
            );
            $result->recordCreated($importCount);

            return $result;
        }

        try {
            /**
             * @var \Magebit\Configurator\Model\Import\Importer $importer
             */
            $importer = $this->importerFactory->create();
            $importer->setEntityCode('customer_composite');
            $importer->setBehavior(Import::BEHAVIOR_APPEND);
            $importer->processImport($customerImport);
            $this->reindex();
            $result->recordCreated($importCount);

            $this->log->logInfo($importer->getLogTrace());
            $this->log->logInfo($importer->getErrorMessages());
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    /**
     * Check the headers have been set correctly.
     *
     * @param array $data
     * @throws ComponentException
     */
    public function getColumnHeaders(array $data): void
    {
        if (!isset($data[0])) {
            throw new ComponentException((string) __('No data has been found in the import file'));
        }
        foreach ($data[0] as $heading) {
            $this->columnHeaders[] = $heading;
        }
        foreach ($this->requiredColumns as $column) {
            if (!in_array($column, $this->columnHeaders)) {
                throw new ComponentException((string) __('The column "%1" is required.', $column));
            }
        }
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->columnHeaders;
    }

    /**
     * Check if the group is valid.
     *
     * @param mixed $group
     */
    public function isValidGroup($group): bool
    {
        if (strlen((string) $group) === 0) {
            return false;
        }
        if ($this->customerGroups === null) {
            $this->customerGroups = [];
            $groups = $this->groupRepository->getList($this->criteriaBuilder->create());
            foreach ($groups->getItems() as $customerGroup) {
                $this->customerGroups[] = $customerGroup->getId();
            }
        }

        return in_array($group, $this->customerGroups);
    }

    public function getDefaultGroupId(): int
    {
        if ($this->groupDefault === null) {
            $this->groupDefault = (int) $this->groupManagement->getDefaultGroup()->getId();
        }

        return $this->groupDefault;
    }

    /**
     * Drop rows belonging to customers whose email already exists. Rows with an
     * empty email are additional addresses for the preceding customer and follow
     * that customer's keep/drop decision.
     *
     * @param array $customerImport
     * @param ComponentResult $result
     * @return array
     */
    private function dropExistingCustomers(array $customerImport, ComponentResult $result): array
    {
        $emails = [];
        foreach ($customerImport as $row) {
            $email = (string) ($row[self::CUSTOMER_EMAIL_HEADER] ?? '');
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        $existing = $this->loadExistingCustomerEmails($emails);
        if ($existing === []) {
            return $customerImport;
        }

        $kept = [];
        $dropCurrent = false;
        $dropped = 0;
        foreach ($customerImport as $row) {
            $email = (string) ($row[self::CUSTOMER_EMAIL_HEADER] ?? '');
            if ($email !== '') {
                // A new customer starts here; decide whether to keep the group.
                $dropCurrent = isset($existing[strtolower($email)]);
                if ($dropCurrent) {
                    $result->recordSkipped();
                }
            }

            if ($dropCurrent) {
                $dropped++;
                continue;
            }

            $kept[] = $row;
        }

        if ($dropped > 0) {
            $this->log->logInfo(sprintf('Create mode: %d existing customer row(s) skipped.', $dropped));
        }

        return $kept;
    }

    /**
     * Load the subset of given emails that already exist, as a lowercased set.
     *
     * @param string[] $emails
     * @return array<string, true>
     */
    private function loadExistingCustomerEmails(array $emails): array
    {
        $emails = array_values(array_unique(array_filter($emails, static fn (string $e): bool => $e !== '')));
        if ($emails === []) {
            return [];
        }

        $collection = $this->customerCollectionFactory->create();
        $collection->addFieldToFilter(self::CUSTOMER_EMAIL_HEADER, ['in' => $emails]);

        $existing = [];
        foreach ($collection as $customer) {
            $existing[strtolower((string) $customer->getEmail())] = true;
        }

        return $existing;
    }

    private function reindex(): void
    {
        $this->log->logInfo('Reindexing the customer grid');
        $customerGrid = $this->indexerFactory->create();
        $customerGrid->load('customer_grid');
        $customerGrid->reindexAll();
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
