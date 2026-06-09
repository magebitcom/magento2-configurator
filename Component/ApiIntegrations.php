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
use Magento\Integration\Model\Integration as IntegrationModel;
use Magento\Integration\Model\IntegrationFactory;
use Magento\Integration\Model\Oauth\TokenFactory;
use Magento\Integration\Model\ResourceModel\Oauth\Token as TokenResource;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\ReconciliationOutcome;
use Magento\Integration\Model\AuthorizationService;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class ApiIntegrations implements ComponentInterface, ExportableComponentInterface
{
    private const ALIAS = 'apiintegrations';
    private const DESCRIPTION = 'Component to create Api Integrations';

    public function __construct(
        private readonly IntegrationFactory $integrationFactory,
        private readonly IntegrationServiceInterface $integrationService,
        private readonly AuthorizationService $authorizationService,
        private readonly TokenFactory $tokenFactory,
        private readonly TokenResource $tokenResource,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
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

                $this->createApiIntegration($integrationData, $context->getMode(), $context->isDryRun(), $result);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    private function createApiIntegration(
        array $integrationData,
        ComponentMode $mode,
        bool $dryRun,
        ComponentResult $result
    ): void {
        $integration = $this->integrationFactory->create();
        $existing = $integration->getCollection()
            ->addFieldToFilter('name', $integrationData['name'])
            ->getFirstItem();
        $exists = (bool) $existing->getId();

        // Explicit removal: `remove: true` deletes the integration if it exists,
        // in either mode. Idempotent — an integration already absent is skipped.
        if (!empty($integrationData['remove'])) {
            $this->removeApiIntegration(
                (string) $integrationData['name'],
                $exists ? (int) $existing->getId() : false,
                $dryRun,
                $result
            );
            return;
        }

        $version = $integrationData['version'] ?? null;
        $request = new ReconciliationRequest(
            self::ALIAS,
            (string) $integrationData['name'],
            $mode,
            $exists,
            $version ? (int) $version : null
        );

        $outcome = $this->gate->decide($request);
        if ($outcome->isSkip()) {
            $this->log->logComment(
                sprintf('API Integration "%s" exists, skipped (create mode)', $integrationData['name'])
            );
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(
                sprintf('[dry-run] Would %s API Integration "%s"', $outcome->value, $integrationData['name'])
            );
            $outcome->record($result);
            return;
        }

        $integrationDataArray = $this->convertToUseableData($integrationData);

        if ($outcome === ReconciliationOutcome::Create) {
            $integration = $this->integrationService->create($integrationDataArray);
            $integrationId = $integration->getId();
            $this->setPermissions($integrationId, $integrationData['resources']);
            // Token is minted only on creation.
            $this->activateAndAuthorize($integration->getConsumerId());
            $this->log->logInfo(sprintf('API Integration "%s" created', $integrationData['name']));
        } else {
            // Maintain: update metadata + re-grant permissions, but NEVER re-mint the
            // access token — doing so would break any live consumer using it.
            $integrationDataArray['integration_id'] = $existing->getId();
            $integration = $this->integrationService->update($integrationDataArray);
            $this->setPermissions($integration->getId(), $integrationData['resources']);
            $this->log->logInfo(sprintf('API Integration "%s" updated (token preserved)', $integrationData['name']));
        }

        $this->gate->commitVersion($request, $dryRun);
        $outcome->record($result);
    }

    /**
     * Delete an integration flagged with `remove: true`. Idempotent: an integration
     * that is already absent records a skip rather than an error. Honors dry-run.
     *
     * @param string $name
     * @param false|int $integrationId
     * @param bool $dryRun
     * @param ComponentResult $result
     * @return void
     */
    private function removeApiIntegration(
        string $name,
        false|int $integrationId,
        bool $dryRun,
        ComponentResult $result
    ): void {
        if (!$integrationId) {
            $this->log->logComment(sprintf("API Integration '%s' not present, nothing to remove", $name));
            $result->recordSkipped();
            return;
        }

        if ($dryRun) {
            $this->log->logInfo(sprintf('[dry-run] Would remove API Integration %s', $name));
        } else {
            $this->integrationService->delete($integrationId);
            $this->log->logInfo(sprintf('Removed API Integration %s', $name));
        }

        $result->recordRemoved();
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
        $this->tokenResource->save($token);
    }

    /**
     * Export current API integrations into the source format. Refresh mode rewrites
     * only the integrations already tracked in the source file (matched by name);
     * full mode dumps every integration (optionally filtered by a name prefix). Only
     * metadata and granted ACL resources are written — access tokens, consumer keys
     * and secrets are NEVER exported.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild the tracked entries from current DB state, matched by name. Preserves
     * non-value keys (e.g. version) and keeps entries whose integration no longer
     * exists unchanged.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        $entries = $existing['apiintegrations'] ?? null;
        if (!is_array($entries)) {
            return $existing;
        }

        $out = [];
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;
            if ($name === null) {
                $out[] = $entry;
                continue;
            }

            $integration = $this->findByName((string) $name);
            if ($integration === null) {
                // Tracked integration no longer exists — keep the entry untouched.
                $out[] = $entry;
                continue;
            }

            $out[] = $this->mergeIntegrationData($entry, $integration);
        }

        return ['apiintegrations' => $out];
    }

    /**
     * Export all integrations in the source format, optionally filtered by a name prefix.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        $collection = $this->integrationFactory->create()->getCollection();
        if ($filter !== null && $filter !== '') {
            $collection->addFieldToFilter('name', ['like' => $filter . '%']);
        }

        $out = [];
        foreach ($collection as $integration) {
            $out[] = $this->buildEntry($integration);
        }

        return ['apiintegrations' => $out];
    }

    /**
     * Merge current DB metadata/resources into an existing tracked entry, preserving
     * any other keys already present (e.g. version, setuptype).
     */
    private function mergeIntegrationData(array $entry, IntegrationModel $integration): array
    {
        return array_merge($entry, $this->buildEntry($integration));
    }

    /**
     * Build a single source-format entry from an integration. Tokens and secrets are
     * never read or written.
     */
    private function buildEntry(IntegrationModel $integration): array
    {
        return [
            'name' => (string) $integration->getName(),
            'email' => (string) $integration->getEmail(),
            'callbackurl' => (string) $integration->getEndpoint(),
            'identityurl' => (string) $integration->getIdentityLinkUrl(),
            'resources' => $this->integrationService->getSelectedResources((int) $integration->getId()),
        ];
    }

    /**
     * Find an integration by its (unique) name, or null if none exists.
     */
    private function findByName(string $name): ?IntegrationModel
    {
        $integration = $this->integrationFactory->create()->getCollection()
            ->addFieldToFilter('name', $name)
            ->getFirstItem();

        return $integration->getId() ? $integration : null;
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
