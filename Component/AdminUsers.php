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
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
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
class AdminUsers implements ComponentInterface
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

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
