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
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Authorization\Model\RoleFactory;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\UserFactory;

/**
 * Creates admin users and assigns them to a role, from configurator YAML.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class AdminUsers implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'adminusers';
    private const DESCRIPTION = 'Component to create admin users.';

    private const REQUIRED_USER_FIELDS = ['username', 'firstname', 'secondname', 'email', 'password'];

    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly RoleFactory $roleFactory,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['adminusers']) || !is_array($data['adminusers'])) {
            $result->addError('No "adminusers" node found in the source data.');
            return $result;
        }

        foreach ($data['adminusers'] as $roleSet) {
            $roleName = $roleSet['rolename'] ?? null;
            $roleId = $roleName !== null ? $this->getUserRoleFromName((string) $roleName) : null;

            if ($roleId === null) {
                $message = sprintf('Admin Role "%s" does not exist', (string) $roleName);
                $this->log->logError($message);
                $result->addError($message);
                continue;
            }

            foreach ($roleSet['users'] ?? [] as $userData) {
                if (!$this->isValidUserData($userData, $result)) {
                    continue;
                }

                try {
                    $this->createAdminUser($userData, $roleId, $context->getMode(), $context->isDryRun(), $result);
                } catch (ValidatorException $e) {
                    $message = sprintf('Magento Framework Validation Exception: %s', $e->getMessage());
                    $this->log->logError($message);
                    $result->addError($message);
                } catch (ComponentException $e) {
                    $this->log->logError($e->getMessage());
                    $result->addError($e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Create an admin user, skipping creation when one with the email already exists.
     *
     * @param array $userData
     */
    private function createAdminUser(
        array $userData,
        int $roleId,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $fullName = $userData['firstname'] . ' ' . $userData['secondname'];

        $user = $this->userFactory->create();
        $existing = $user->getCollection()->addFieldToFilter('email', $userData['email'])->getFirstItem();
        $exists = (bool) $existing->getId();

        $version = $userData['version'] ?? null;
        $request = new ReconciliationRequest(
            self::ALIAS,
            (string) $userData['email'],
            $mode,
            $exists,
            $version ? (int) $version : null
        );

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logComment(sprintf(
                'Admin User "%s" (%s) skipped (create mode)',
                $fullName,
                $userData['email']
            ));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf(
                '[dry-run] Would %s Admin User "%s" (%s)',
                $outcome->value,
                $fullName,
                $userData['email']
            ));
            $outcome->record($result);
            return;
        }

        $isCreate = $outcome === ReconciliationOutcome::Create;
        $user = $isCreate ? $user : $this->userFactory->create()->load($existing->getId());

        $this->log->logInfo(sprintf('Admin User "%s" (%s) being %s', $fullName, $userData['email'], $outcome->value));

        $user
            ->setUserName($userData['username'])
            ->setFirstName($userData['firstname'])
            ->setLastName($userData['secondname'])
            ->setEmail($userData['email'])
            ->setIsActive(true)
            ->setRoleId($roleId);

        // Only set the password on creation; re-setting it on every maintain run
        // would re-hash and force the user to reset their password.
        if ($isCreate) {
            $user->setPassword($userData['password']);
        }

        if (array_key_exists('interface_locale', $userData)) {
            $user->setInterfaceLocale($userData['interface_locale']);
        }

        if ($user->validate() !== true) {
            $message = sprintf('Admin User "%s" failed validation and was not saved', $fullName);
            $this->log->logError($message);
            $result->addError($message);
            return;
        }

        $this->userResource->save($user);
        $this->gate->commitVersion($request, $dryRun);
        $outcome->record($result);
        $this->log->logInfo(sprintf('Admin User "%s" %s successfully', $fullName, $outcome->value));
    }

    /**
     * Resolve a role id from its name.
     */
    private function getUserRoleFromName(string $roleName): ?int
    {
        $role = $this->roleFactory->create()
            ->getCollection()
            ->addFieldToFilter('role_name', $roleName)
            ->getFirstItem();

        return $role->getId() ? (int) $role->getId() : null;
    }

    /**
     * Ensure all required user fields are present and non-empty.
     *
     * @param mixed $userData
     */
    private function isValidUserData($userData, ComponentResult $result): bool
    {
        $missing = [];
        foreach (self::REQUIRED_USER_FIELDS as $field) {
            if (!is_array($userData) || !isset($userData[$field]) || $userData[$field] === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            $message = 'Admin User data is missing required field(s): ' . implode(', ', $missing);
            $this->log->logError($message);
            $result->addError($message);
            return false;
        }

        return true;
    }

    /**
     * Export current admin users into the source format. Refresh mode rewrites
     * only the role sets / users already tracked in the source file, pulling each
     * tracked user's current fields from the DB (matched by email); a tracked user
     * that no longer exists is kept untouched. Full mode dumps every admin user,
     * grouped by their assigned role's name (optionally filtered by role-name
     * prefix). The password is a secret and is never written out.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Refresh each tracked user's value fields from the DB (matched by email),
     * preserving any other keys (version, interface_locale) and the role grouping.
     * Users with no matching DB record are kept exactly as they are in the source.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        if (!isset($existing['adminusers']) || !is_array($existing['adminusers'])) {
            return $existing;
        }

        $out = $existing;
        foreach ($existing['adminusers'] as $i => $roleSet) {
            if (!is_array($roleSet) || !isset($roleSet['users']) || !is_array($roleSet['users'])) {
                continue;
            }

            $users = [];
            foreach ($roleSet['users'] as $userData) {
                $email = is_array($userData) ? ($userData['email'] ?? null) : null;
                $current = $email !== null ? $this->loadUserByEmail((string) $email) : null;

                if ($current === null) {
                    $users[] = $userData;
                    continue;
                }

                $users[] = $this->refreshUserEntry((array) $userData, $current);
            }

            $out['adminusers'][$i]['users'] = $users;
        }

        return $out;
    }

    /**
     * Rebuild a tracked user entry from its current DB values, preserving any
     * non-value keys already present in the source (e.g. version). The password
     * is never written out.
     *
     * @param array $entry
     * @param array $values Current DB values keyed by source field.
     * @return array
     */
    private function refreshUserEntry(array $entry, array $values): array
    {
        unset($entry['password']);

        foreach ($values as $key => $value) {
            if ($value !== null) {
                $entry[$key] = $value;
            }
        }

        return $entry;
    }

    /**
     * Dump every admin user grouped by their assigned role name. When a filter is
     * given, only roles whose name starts with it are exported. The password is
     * never written out.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $grouped = [];
        $order = [];

        foreach ($this->userFactory->create()->getCollection() as $user) {
            $roleName = $this->getRoleNameForUser($user);
            if ($roleName === null) {
                continue;
            }

            if ($filter !== null && $filter !== '' && !str_starts_with($roleName, $filter)) {
                continue;
            }

            if (!isset($grouped[$roleName])) {
                $grouped[$roleName] = [];
                $order[] = $roleName;
            }

            $grouped[$roleName][] = $this->userToEntry($user);
        }

        $out = ['adminusers' => []];
        foreach ($order as $roleName) {
            $out['adminusers'][] = [
                'rolename' => $roleName,
                'users' => $grouped[$roleName],
            ];
        }

        return $out;
    }

    /**
     * Map a user model to a source-format user entry (no password).
     *
     * @param mixed $user
     * @return array
     */
    private function userToEntry($user): array
    {
        $entry = [
            'username' => (string) $user->getUserName(),
            'firstname' => (string) $user->getFirstName(),
            'secondname' => (string) $user->getLastName(),
            'email' => (string) $user->getEmail(),
        ];

        $locale = $user->getInterfaceLocale();
        if ($locale !== null && $locale !== '') {
            $entry['interface_locale'] = (string) $locale;
        }

        return $entry;
    }

    /**
     * Load an admin user by email; null when none exists.
     *
     * @return array|null Current values keyed by source field (no password), or null.
     */
    private function loadUserByEmail(string $email): ?array
    {
        $user = $this->userFactory->create()->getCollection()
            ->addFieldToFilter('email', $email)
            ->getFirstItem();

        if (!$user->getId()) {
            return null;
        }

        return $this->userToEntry($user);
    }

    /**
     * Resolve the assigned role's name for a user; null when no role is assigned.
     *
     * @param mixed $user
     */
    private function getRoleNameForUser($user): ?string
    {
        $roleId = $user->getRole() ? $user->getRole()->getId() : $user->getRoleId();
        if (!$roleId) {
            return null;
        }

        $role = $this->roleFactory->create()->load((int) $roleId);

        return $role->getId() ? (string) $role->getRoleName() : null;
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
