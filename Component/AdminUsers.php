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
                try {
                    // Explicit removal: `remove: true` deletes the user if it exists,
                    // in either mode. Idempotent — a user already absent is skipped.
                    // Checked before required-field validation so a removal entry,
                    // which only needs an email, does not trip it.
                    if (is_array($userData) && !empty($userData['remove'])) {
                        $this->removeAdminUser($userData, $context->isDryRun(), $result);
                        continue;
                    }

                    if (!$this->isValidUserData($userData, $result)) {
                        continue;
                    }

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
     * Delete an admin user flagged with `remove: true`, matched by email.
     * Idempotent: a user that is already absent records a skip rather than an
     * error. Honors dry-run.
     *
     * @param array $userData
     */
    private function removeAdminUser(array $userData, bool $dryRun, ComponentResult $result): void
    {
        $email = $userData['email'] ?? null;
        if ($email === null || $email === '') {
            $message = 'Admin User removal entry is missing the required "email" field';
            $this->log->logError($message);
            $result->addError($message);
            return;
        }

        $existing = $this->userFactory->create()->getCollection()
            ->addFieldToFilter('email', $email)
            ->getFirstItem();

        if (!$existing->getId()) {
            $this->log->logComment(sprintf("Admin User '%s' not present, nothing to remove", $email));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove Admin User %s', $email));
        } else {
            $this->userResource->delete($existing);
            $this->log->logInfo(sprintf('Removed Admin User %s', $email));
        }

        $result->recordRemoved();
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
     * Export current admin users into the source format. Refresh mode re-reads
     * every tracked user's full current state from the DB (matched by email):
     * all value fields (username/firstname/secondname/email, optional
     * interface_locale) plus the `role` relation, which is re-resolved so the
     * user is regrouped under its current role's name — admin changes to either a
     * field or the assigned role are captured, not just the keys already tracked.
     * A tracked user that no longer exists is kept untouched under its original
     * role set. Full mode dumps every admin user, grouped by their assigned
     * role's name (optionally filtered by role-name prefix). The password is a
     * secret and is never written out.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Re-read each tracked user's full current state from the DB (matched by
     * email), re-resolving the `role` relation so users land under their current
     * role's name. Users with no matching DB record are kept exactly as they are
     * in the source, under their original role set. Role sets that hold no tracked
     * users (or are malformed) are preserved as-is so non-user keys survive.
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

        // Index role sets by name so a user whose role changed in the DB can be
        // moved into the matching tracked role set (when one exists).
        $roleSetIndex = [];
        foreach ($existing['adminusers'] as $i => $roleSet) {
            if (is_array($roleSet) && isset($roleSet['rolename'])) {
                $roleSetIndex[(string) $roleSet['rolename']] = $i;
            }
        }

        // Pass 1: strip every tracked user from its source role set, refreshing
        // each one's full state (or keeping it untouched when it no longer exists).
        // Collect the refreshed entries together with their current DB role name.
        $refreshed = [];
        foreach ($existing['adminusers'] as $i => $roleSet) {
            if (!is_array($roleSet) || !isset($roleSet['users']) || !is_array($roleSet['users'])) {
                continue;
            }

            $keep = [];
            foreach ($roleSet['users'] as $userData) {
                $email = is_array($userData) ? ($userData['email'] ?? null) : null;
                $user = $email !== null ? $this->loadUserByEmail((string) $email) : null;

                if ($user === null) {
                    // No matching DB record: keep the source entry where it is.
                    $keep[] = $userData;
                    continue;
                }

                $refreshed[] = [
                    'rolename' => $this->getRoleNameForUser($user),
                    'entry' => $this->refreshUserEntry((array) $userData, $user),
                ];
            }

            $out['adminusers'][$i]['users'] = $keep;
        }

        // Pass 2: place each refreshed user under its current role's role set,
        // creating a new role set when the role isn't already tracked.
        foreach ($refreshed as $item) {
            $roleName = $item['rolename'];

            if ($roleName === null) {
                continue;
            }

            if (!isset($roleSetIndex[$roleName])) {
                $out['adminusers'][] = ['rolename' => $roleName, 'users' => []];
                $roleSetIndex[$roleName] = array_key_last($out['adminusers']);
            }

            $out['adminusers'][$roleSetIndex[$roleName]]['users'][] = $item['entry'];
        }

        return $out;
    }

    /**
     * Rebuild a tracked user entry from its full current DB state, dropping stale
     * keys not backed by a current value and preserving any non-DB structural keys
     * already present in the source (e.g. version). Null/empty values are skipped
     * to keep the file clean. The password is never written out.
     *
     * @param array $entry Existing tracked source entry.
     * @param mixed $user Loaded admin user model.
     * @return array
     */
    private function refreshUserEntry(array $entry, $user): array
    {
        // Reuse the full-export builder so a tracked user comes back with its
        // complete current field set, not just the keys the source already lists.
        $fresh = $this->userToEntry($user);

        // Carry over non-DB structural keys the source tracked (e.g. version),
        // never the password.
        foreach ($entry as $key => $value) {
            if ($key === 'password' || array_key_exists($key, $fresh)) {
                continue;
            }
            // interface_locale is a DB-backed field handled by userToEntry; if the
            // user no longer has one, drop it rather than preserving a stale value.
            if ($key === 'interface_locale') {
                continue;
            }
            $fresh[$key] = $value;
        }

        return $fresh;
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
     * Load an admin user model by email; null when none exists.
     *
     * @return mixed|null Loaded admin user model, or null.
     */
    private function loadUserByEmail(string $email)
    {
        $user = $this->userFactory->create()->getCollection()
            ->addFieldToFilter('email', $email)
            ->getFirstItem();

        if (!$user->getId()) {
            return null;
        }

        return $user;
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
