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
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Authorization\Model\Acl\Role\Group as RoleGroup;
use Magento\Authorization\Model\ResourceModel\Role as RoleResource;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\RulesFactory;
use Magento\Authorization\Model\UserContextInterface;

/**
 * Creates admin roles and assigns their ACL resources from configurator YAML.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class AdminRoles implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'adminroles';
    private const DESCRIPTION = 'Component to create admin roles.';

    public function __construct(
        private readonly RoleFactory $roleFactory,
        private readonly RoleResource $roleResource,
        private readonly RulesFactory $rulesFactory,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
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
                $this->createAdminRole(
                    $role['name'],
                    $role['resources'] ?? null,
                    $context->getMode(),
                    $role['version'] ?? null,
                    $context->isDryRun(),
                    $result
                );
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
        ComponentMode $mode,
        ?string $version,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $role = $this->roleFactory->create();
        $existing = $role->getCollection()->addFieldToFilter('role_name', $roleName)->getFirstItem();
        $exists = (bool) $existing->getId();

        $request = new ReconciliationRequest(self::ALIAS, $roleName, $mode, $exists, $version ? (int) $version : null);

        if ($this->gate->decide($request)->isSkip()) {
            // In create mode an existing role (and its resources) is left untouched.
            $this->log->logInfo(sprintf('Admin Role "%s" exists, skipped (create mode)', $roleName));
            $result->recordSkipped();
            return;
        }

        // Existing role in maintain mode (or a version bump): reconcile its resources.
        if ($exists) {
            $this->setResourceIds($existing, $resources, $dryRun);
            $this->gate->commitVersion($request, $dryRun);
            $result->recordUpdated();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would create Admin Role "%s"', $roleName));
            $this->setResourceIds($existing, $resources, $dryRun);
            $this->gate->commitVersion($request, $dryRun);
            $result->recordCreated();
            return;
        }

        $this->log->logInfo(sprintf('Admin Role "%s" being created', $roleName));

        $role->setRoleName($roleName)
            ->setParentId(0)
            ->setRoleType(RoleGroup::ROLE_TYPE)
            ->setUserType(UserContextInterface::USER_TYPE_ADMIN)
            ->setSortOrder(0);
        $this->roleResource->save($role);

        $this->setResourceIds($role, $resources, $dryRun);
        $this->gate->commitVersion($request, $dryRun);
        $result->recordCreated();
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

    /**
     * Export current admin roles into the source format. Refresh mode rewrites
     * only the roles already tracked in the source file (matched by name),
     * rebuilding their `resources` from the DB while preserving other keys
     * (e.g. version); roles no longer in the DB are kept untouched. Full mode
     * dumps every admin role (optionally filtered by a role-name prefix).
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData(), $context->getFilter());
    }

    /**
     * @param array $existing
     * @param string|null $filter
     * @return array
     */
    private function refreshTracked(array $existing, ?string $filter): array
    {
        $roles = $existing['adminroles'] ?? null;
        if (!is_array($roles)) {
            return ['adminroles' => []];
        }

        $out = [];
        foreach ($roles as $role) {
            if (!is_array($role) || !isset($role['name'])) {
                // Not a recognisable tracked entry; keep it unchanged.
                $out[] = $role;
                continue;
            }

            $name = (string) $role['name'];
            if ($filter !== null && $filter !== '' && !str_starts_with($name, $filter)) {
                $out[] = $role;
                continue;
            }

            $roleId = $this->findRoleIdByName($name);
            if ($roleId === null) {
                // Tracked role no longer exists in the DB: keep the entry as-is.
                $out[] = $role;
                continue;
            }

            $role['resources'] = $this->getResourceIdsForRole($roleId);
            $out[] = $role;
        }

        return ['adminroles' => $out];
    }

    /**
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $collection = $this->roleFactory->create()->getCollection()
            ->addFieldToFilter('role_type', RoleGroup::ROLE_TYPE)
            ->addFieldToFilter('user_type', UserContextInterface::USER_TYPE_ADMIN);

        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter('role_name', ['like' => $filter . '%']);
        }

        $out = [];
        foreach ($collection as $role) {
            $out[] = [
                'name' => (string) $role->getRoleName(),
                'resources' => $this->getResourceIdsForRole((int) $role->getId()),
            ];
        }

        return ['adminroles' => $out];
    }

    /**
     * Find an admin role id by its name, or null when it does not exist.
     */
    private function findRoleIdByName(string $roleName): ?int
    {
        $existing = $this->roleFactory->create()
            ->getCollection()
            ->addFieldToFilter('role_name', $roleName)
            ->getFirstItem();

        return $existing->getId() ? (int) $existing->getId() : null;
    }

    /**
     * Read the ACL resource ids a role is allowed to access, in the same form
     * `setResources()` consumes (the `allow` permission rows).
     *
     * @return string[]
     */
    private function getResourceIdsForRole(int $roleId): array
    {
        $rulesCollection = $this->rulesFactory->create()
            ->getCollection()
            ->addFieldToFilter('role_id', $roleId)
            ->addFieldToFilter('permission', 'allow');

        $resources = [];
        foreach ($rulesCollection as $rule) {
            $resourceId = $rule->getResourceId();
            if ($resourceId !== null && $resourceId !== '') {
                $resources[] = (string) $resourceId;
            }
        }

        return $resources;
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
