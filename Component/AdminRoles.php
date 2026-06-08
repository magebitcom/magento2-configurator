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
use Magento\Authorization\Model\Acl\Role\Group as RoleGroup;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\RulesFactory;
use Magento\Authorization\Model\UserContextInterface;

/**
 * Creates admin roles and assigns their ACL resources from configurator YAML.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class AdminRoles implements ComponentInterface
{
    private const ALIAS = 'adminroles';
    private const DESCRIPTION = 'Component to create admin roles.';

    public function __construct(
        private readonly RoleFactory $roleFactory,
        private readonly RulesFactory $rulesFactory,
        private readonly LoggerInterface $log
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['adminroles']) || !is_array($data['adminroles'])) {
            $result->addError('No "adminroles" node found in the source data.');
            return $result;
        }

        foreach ($data['adminroles'] as $role) {
            try {
                if (!isset($role['name'])) {
                    throw new ComponentException((string) __('An adminroles entry is missing the "name" key.'));
                }
                $this->createAdminRole($role['name'], $role['resources'] ?? null, $context->isDryRun(), $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Create the admin role (or update its resources if it already exists).
     *
     * @param string $roleName
     * @param array|null $resources
     */
    private function createAdminRole(
        string $roleName,
        ?array $resources,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $role = $this->roleFactory->create();
        $existing = $role->getCollection()->addFieldToFilter('role_name', $roleName)->getFirstItem();

        if ($existing->getId()) {
            $this->log->logInfo(sprintf('Admin Role "%s" already exists, creation skipped', $roleName));
            $result->recordSkipped();
            $this->setResourceIds($existing, $resources, $dryRun);
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would create Admin Role "%s"', $roleName));
            $result->recordCreated();
            return;
        }

        $this->log->logInfo(sprintf('Admin Role "%s" being created', $roleName));

        $role->setRoleName($roleName)
            ->setParentId(0)
            ->setRoleType(RoleGroup::ROLE_TYPE)
            ->setUserType(UserContextInterface::USER_TYPE_ADMIN)
            ->setSortOrder(0)
            ->save();

        $result->recordCreated();
        $this->setResourceIds($role, $resources, $dryRun);
    }

    /**
     * Assign the resource ids the admin role may access.
     *
     * @param \Magento\Authorization\Model\Role $role
     * @param array|null $resources
     */
    private function setResourceIds($role, ?array $resources, bool $dryRun): void
    {
        $roleName = $role->getRoleName();

        if ($resources === null) {
            $this->log->logError(
                sprintf('Admin Role "%s" resources are empty, please check your yaml file', $roleName)
            );
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would update resources for Admin Role "%s"', $roleName));
            return;
        }

        $this->log->logInfo(sprintf('Admin Role "%s" resources updating', $roleName));
        $this->rulesFactory->create()->setRoleId($role->getId())->setResources($resources)->saveRel();
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
