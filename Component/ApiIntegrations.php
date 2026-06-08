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
use Magento\Integration\Model\IntegrationFactory;
use Magento\Integration\Model\Oauth\TokenFactory;
use Magebit\Configurator\Api\LoggerInterface;
use Magento\Integration\Model\AuthorizationService;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class ApiIntegrations implements ComponentInterface
{
    private const ALIAS = 'apiintegrations';
    private const DESCRIPTION = 'Component to create Api Integrations';

    public function __construct(
        private readonly IntegrationFactory $integrationFactory,
        private readonly IntegrationServiceInterface $integrationService,
        private readonly AuthorizationService $authorizationService,
        private readonly TokenFactory $tokenFactory,
        private readonly LoggerInterface $log
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['apiintegrations']) || !is_array($data['apiintegrations'])) {
            $result->addError('No "apiintegrations" node found in the source data.');
            return $result;
        }

        foreach ($data['apiintegrations'] as $integrationData) {
            try {
                if (!isset($integrationData['name'])) {
                    $this->log->logError('Api Integration requires a Name to be set');
                    continue;
                }

                $this->createApiIntegration($integrationData, $context->isDryRun(), $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    private function createApiIntegration(array $integrationData, bool $dryRun, ComponentResult $result): void
    {
        $integration = $this->integrationFactory->create();
        $integrationCount = $integration->getCollection()
            ->addFieldToFilter('name', $integrationData['name'])
            ->getSize();

        if ($integrationCount > 0) {
            $integration = $integration
                ->getCollection()
                ->addFieldToFilter('name', $integrationData['name'])
                ->getFirstItem();

            $this->log->logComment(
                sprintf('API Integration "%s" already exists: Creation skipped', $integration->getName())
            );
            $result->recordSkipped();

            return;
        }

        if ($dryRun) {
            $this->log->logInfo(
                sprintf('[dry-run] Would create API Integration "%s"', $integrationData['name'])
            );
            $result->recordCreated();
            return;
        }

        $integrationDataArray = $this->convertToUseableData($integrationData);
        $integration = $this->integrationService->create($integrationDataArray);
        $integrationId = $integration->getId();

        $this->log->logInfo(
            sprintf('API Integration "%s" created', $integrationData['name'])
        );
        $result->recordCreated();

        $this->setPermissions($integrationId, $integrationData['resources']);
        $this->activateAndAuthorize($integration->getConsumerId());

        $this->log->logInfo(
            sprintf('API Integration "%s" permissions and authorisation set.', $integrationData['name'])
        );
    }

    /**
     * Prepare data for integrationFactory creation
     */
    private function convertToUseableData(array $integrationData): array
    {
        return [
            'name' => $integrationData['name'],
            'email' => $integrationData['email'],
            'status' => '1',
            'endpoint' => $integrationData['callbackurl'],
            'identity_link_url' => $integrationData['identityurl'],
            'setup_type' => 0
        ];
    }

    /**
     * Set permissions for API Integration
     */
    private function setPermissions($integrationId, ?array $resources = null): void
    {
        $authorizationService = $this->authorizationService;
        $authorizationService->grantPermissions($integrationId, $resources);
    }

    /**
     * Activate and Authorize the Integration
     */
    private function activateAndAuthorize($consumerId): void
    {
        $token = $this->tokenFactory->create();
        $token->createVerifierToken($consumerId);
        $token->setType('access');
        $token->save();
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
