<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Component\Media;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;
use Magento\MediaGalleryApi\Api\SaveAssetsInterface;
use Magento\MediaGallerySynchronizationApi\Model\CreateAssetFromFileInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MediaTest extends TestCase
{
    private DirectoryList&MockObject $directoryList;
    private LoggerInterface&MockObject $log;
    private CreateAssetFromFileInterface&MockObject $createAssetFromFile;
    private SaveAssetsInterface&MockObject $saveAssets;
    private GetAssetsByPathsInterface&MockObject $getAssetsByPaths;
    private Media $component;

    /** Real, unique temp directory standing in for the Magento media root. */
    private string $mediaRoot;

    protected function setUp(): void
    {
        $this->directoryList = $this->createMock(DirectoryList::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->createAssetFromFile = $this->createMock(CreateAssetFromFileInterface::class);
        $this->saveAssets = $this->createMock(SaveAssetsInterface::class);
        $this->getAssetsByPaths = $this->createMock(GetAssetsByPathsInterface::class);

        $this->mediaRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'configurator-media-' . uniqid('', true);
        mkdir($this->mediaRoot, 0777, true);
        $this->directoryList->method('getPath')->willReturn($this->mediaRoot);

        $this->component = new Media(
            $this->directoryList,
            $this->log,
            $this->createAssetFromFile,
            $this->saveAssets,
            $this->getAssetsByPaths
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->mediaRoot);
    }

    public function testRecordsErrorWhenNoMediaNodePresent(): void
    {
        // Empty source data -> nothing to process, surfaced as an error.
        $this->saveAssets->expects($this->never())->method('execute');

        $result = $this->execute([]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testDryRunCreatesDirectoryWithoutTouchingDisk(): void
    {
        // A folder node that does not yet exist on disk.
        $result = $this->execute(['wysiwyg' => []], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
        // Dry-run must not actually create the directory.
        $this->assertDirectoryDoesNotExist($this->mediaRoot . DIRECTORY_SEPARATOR . 'wysiwyg');
    }

    public function testDryRunDownloadsNothingButRecordsIntent(): void
    {
        // A file item (numeric index) inside a folder; dry-run must not fetch it.
        $data = [
            'catalog' => [
                ['name' => 'logo.png', 'location' => 'http://example.test/logo.png'],
            ],
        ];

        $result = $this->execute($data, true);

        $this->assertTrue($result->isSuccessful());
        // One create for the parent folder + one for the would-be download.
        $this->assertSame(2, $result->getCreated());
        $this->assertFileDoesNotExist($this->mediaRoot . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'logo.png');
    }

    public function testSkipsExistingDirectory(): void
    {
        // Pre-create the directory so the component sees it as already present.
        mkdir($this->mediaRoot . DIRECTORY_SEPARATOR . 'wysiwyg', 0777, true);

        $result = $this->execute(['wysiwyg' => []], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testSkipsExistingFile(): void
    {
        // Pre-create both the folder and the target file.
        $dir = $this->mediaRoot . DIRECTORY_SEPARATOR . 'catalog';
        mkdir($dir, 0777, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'logo.png', 'already here');

        $data = [
            'catalog' => [
                ['name' => 'logo.png', 'location' => 'http://example.test/logo.png'],
            ],
        ];

        $result = $this->execute($data, true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getCreated());
        // The pre-existing folder and the pre-existing file are both skipped.
        $this->assertSame(2, $result->getSkipped());
    }

    public function testFileItemWithoutNameIsSkippedQuietly(): void
    {
        // A numeric child lacking a "name" key throws internally and is logged,
        // never persisted; the run still completes successfully.
        $this->log->expects($this->atLeastOnce())->method('logError');
        $this->saveAssets->expects($this->never())->method('execute');

        $data = [
            'catalog' => [
                ['location' => 'http://example.test/logo.png'],
            ],
        ];

        $result = $this->execute($data, true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getSkipped());
    }

    public function testFileItemWithoutLocationIsSkippedQuietly(): void
    {
        // A numeric child lacking a "location" key is logged and not downloaded.
        $this->log->expects($this->atLeastOnce())->method('logError');
        $this->saveAssets->expects($this->never())->method('execute');

        $data = [
            'catalog' => [
                ['name' => 'logo.png'],
            ],
        ];

        $result = $this->execute($data, true);

        $this->assertTrue($result->isSuccessful());
    }

    /**
     * @param array $data full parsed source (iterated directly by the component)
     */
    private function execute(
        array $data,
        bool $dryRun = false,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
