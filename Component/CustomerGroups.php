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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Tax\Api\TaxClassRepositoryInterface;

/**
 * Creates customer groups, each linked to a tax class, from configurator YAML.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class CustomerGroups implements ComponentInterface
{
    private const ALIAS = 'customergroups';
    private const DESCRIPTION = 'Component to create customer groups.';

    /** Magento's customer_group_code column is limited to this many characters. */
    private const MAX_GROUP_NAME_LENGTH = 32;

    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly GroupInterfaceFactory $groupFactory,
        private readonly TaxClassRepositoryInterface $taxClassRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $log
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['customergroups']) || !is_array($data['customergroups'])) {
            $result->addError('No "customergroups" node found in the source data.');
            return $result;
        }

        foreach ($data['customergroups'] as $taxClassConfig) {
            $taxClassName = $taxClassConfig['taxclass'] ?? null;
            if ($taxClassName === null) {
                $result->addError('A "customergroups" entry is missing the required "taxclass" key.');
                continue;
            }

            $taxClassId = $this->getTaxClassIdByName((string) $taxClassName, $result);
            if ($taxClassId === null) {
                continue;
            }

            foreach ($taxClassConfig['groups'] ?? [] as $group) {
                try {
                    $this->createCustomerGroup($this->extractGroupName($group), $taxClassId, $context, $result);
                } catch (ComponentException $e) {
                    $this->log->logError($e->getMessage());
                    $result->addError($e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Create a customer group, skipping creation when one with the same code exists.
     */
    private function createCustomerGroup(
        string $groupName,
        int $taxClassId,
        ComponentContext $context,
        ComponentResult $result
    ): void {
        if ($this->groupExists($groupName)) {
            $this->log->logInfo(sprintf('Customer Group "%s" already exists, creation skipped', $groupName));
            $result->recordSkipped();
            return;
        }

        if ($context->isDryRun()) {
            $this->log->logInfo(sprintf('[dry-run] Would create Customer Group "%s"', $groupName));
            $result->recordCreated();
            return;
        }

        $group = $this->groupFactory->create();
        $group->setCode($groupName);
        $group->setTaxClassId($taxClassId);
        $this->groupRepository->save($group);

        $this->log->logInfo(sprintf('Customer Group "%s" created', $groupName));
        $result->recordCreated();
    }

    private function groupExists(string $groupName): bool
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(GroupInterface::CODE, $groupName)
            ->create();

        return $this->groupRepository->getList($criteria)->getTotalCount() > 0;
    }

    /**
     * Validate and return the group name from a single group config entry.
     *
     * @param array $group
     * @throws ComponentException
     */
    private function extractGroupName(array $group): string
    {
        if (!isset($group['name']) || $group['name'] === '') {
            throw new ComponentException((string) __('The customer group name is mandatory'));
        }

        $name = (string) $group['name'];
        if (strlen($name) > self::MAX_GROUP_NAME_LENGTH) {
            throw new ComponentException((string) __(
                'The customer group name "%1" is too long (maximum length is %2 characters)',
                $name,
                self::MAX_GROUP_NAME_LENGTH
            ));
        }

        return $name;
    }

    /**
     * Resolve a tax class id from its name, or null (logged + recorded) when missing.
     */
    private function getTaxClassIdByName(string $taxClassName, ComponentResult $result): ?int
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('class_name', $taxClassName)
            ->create();

        $taxClasses = $this->taxClassRepository->getList($criteria)->getItems();
        $taxClass = $taxClasses === [] ? null : current($taxClasses);

        if ($taxClass === null) {
            $message = sprintf('There is no Tax class with the name "%s" in this database', $taxClassName);
            $this->log->logError($message);
            $result->addError($message);
            return null;
        }

        return (int) $taxClass->getClassId();
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
