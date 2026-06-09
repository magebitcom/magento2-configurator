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
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;
use Magento\MediaGalleryApi\Api\SaveAssetsInterface;
use Magento\MediaGallerySynchronizationApi\Model\CreateAssetFromFileInterface;

class Media implements ComponentInterface
{
    public const FULL_ACCESS = 0777;

    private const ALIAS = 'media';
    private const DESCRIPTION = 'Component to download/maintain media.';

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly LoggerInterface $log,
        private readonly CreateAssetFromFileInterface $createAssetFromFile,
        private readonly SaveAssetsInterface $saveAssets,
        private readonly GetAssetsByPathsInterface $getAssetsByPaths
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!is_array($data) || $data === []) {
            $result->addError('No "media" node found in the source data.');
            return $result;
        }

        try {
            // Load root media path
            $mediaPath = $this->directoryList->getPath(DirectoryList::MEDIA);

            // Loop through top level nodes
            foreach ($data as $name => $childNode) {
                // Create a child folder or file item
                $this->createChildFolderFileItem($mediaPath, $name, $childNode, $context->isDryRun(), $result);
            }
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage());
            $result->addError($e->getMessage());
        }

        return $result;
    }

    private function createChildFolderFileItem(
        $currentPath,
        $name,
        $node,
        bool $dryRun,
        ComponentResult $result,
        int $nest = 0
    ): void {
        try {
            // Update the current path to the new path
            $newPath = $currentPath . DIRECTORY_SEPARATOR . $name;

            // Check if a folder exists and create if required
            $this->checkAndCreateFolder($newPath, $name, $nest, $dryRun, $result);

            // If the node does not have a numeric index
            if (!is_numeric($name)) {
                $nest++;

                // Loop through the child node
                foreach ($node as $childName => $childNode) {
                    // Create a child folder
                    $this->createChildFolderFileItem($newPath, $childName, $childNode, $dryRun, $result, $nest);
                }

                return;
            }

            if (!isset($node['name'])) {
                throw new ComponentException(
                    (string) __('No name set for a child item in %1', $currentPath)
                );
            }

            if (!isset($node['location'])) {
                throw new ComponentException(
                    (string) __('No location set for a child item in %1', $currentPath)
                );
            }

            $newPath = $currentPath . DIRECTORY_SEPARATOR . $node['name'];

            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            if (file_exists($newPath)) {
                $this->log->logComment(sprintf('File already exists: %s', $newPath), $nest);
                $result->recordSkipped();
                return;
            }

            // Download the file and place it in the price place
            $this->downloadAndSetFile($newPath, $node, $nest, $dryRun, $result);
        } catch (ComponentException $e) {
            $this->log->logError($e->getMessage(), $nest);
        }
    }

    private function checkAndCreateFolder($newPath, $name, int $nest, bool $dryRun, ComponentResult $result): void
    {
        // Check if the file/folder exists
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!file_exists($newPath)) {
            // If the node does not have a numeric index
            if (!is_numeric($name)) {
                if ($dryRun) {
                    $this->log->logInfo(sprintf('[dry-run] Would create new media directory %s', $name), $nest);
                    $result->recordCreated();
                    return;
                }

                // Then it is a directory so create it
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                mkdir($newPath, $this::FULL_ACCESS, true);
                $this->log->logInfo(sprintf('Created new media directory %s', $name), $nest);
                $result->recordCreated();
            }

            return;
        }

        // If the node does not have a numeric index
        if (!is_numeric($name)) {
            $this->log->logComment(sprintf('Directory Exists %s', $name), $nest);
            $result->recordSkipped();
        }
    }

    private function downloadAndSetFile($path, $node, int $nest, bool $dryRun, ComponentResult $result): void
    {
        if ($dryRun) {
            $this->log->logInfo(
                sprintf('[dry-run] Would download contents of file from %s to %s', $node['location'], $path),
                $nest
            );
            $result->recordCreated();
            return;
        }

        $this->log->logInfo(sprintf('Downloading contents of file from %s', $node['location']), $nest);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $fileContents = file_get_contents($node['location']);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        file_put_contents($path, $fileContents);
        $this->log->logInfo(sprintf('Created new file: %s', $path), $nest);
        $this->registerMediaGalleryAsset($path, $nest);
        $result->recordCreated();
    }

    /**
     * Register WYSIWYG media in the media gallery so it shows in the admin gallery UI.
     *
     * Only wysiwyg assets are registered (matching how the admin gallery indexes them).
     * Failures are logged but never abort the run.
     */
    private function registerMediaGalleryAsset(string $path, int $nest): void
    {
        if (!str_contains($path, DIRECTORY_SEPARATOR . 'wysiwyg' . DIRECTORY_SEPARATOR)) {
            return;
        }

        try {
            $mediaPath = $this->directoryList->getPath(DirectoryList::MEDIA);
            $relativePath = str_replace($mediaPath . DIRECTORY_SEPARATOR, '', $path);
            $relativePath = str_replace('\\', '/', $relativePath);
            $normalizedPath = '/' . $relativePath;

            // Skip if the asset is already registered.
            if ($this->getAssetsByPaths->execute([$normalizedPath]) !== []) {
                return;
            }

            $asset = $this->createAssetFromFile->execute($relativePath);
            $this->saveAssets->execute([$asset]);
            $this->log->logInfo(sprintf('Registered media asset: %s', $relativePath), $nest);
        } catch (\Exception $e) {
            $this->log->logError(
                sprintf('Failed to register media asset %s: %s', $path, $e->getMessage()),
                $nest
            );
        }
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
