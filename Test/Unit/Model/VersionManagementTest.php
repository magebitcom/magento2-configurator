<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model;

use Magebit\Configurator\Api\Data\ConfigInterfaceFactory;
use Magebit\Configurator\Model\Config;
use Magebit\Configurator\Model\ConfigRepository;
use Magebit\Configurator\Model\VersionManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VersionManagementTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private ConfigInterfaceFactory&MockObject $configFactory;
    private VersionManagement $versionManagement;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configFactory = $this->getMockBuilder(ConfigInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->versionManagement = new VersionManagement(
            $this->configRepository,
            $this->configFactory
        );
    }

    public function testGetCurrentVersionReturnsStoredValuePrefixed(): void
    {
        // An existing record (non-zero id) is read back as its stored integer value.
        $config = $this->givenStoredConfig(42, '7');

        $this->configRepository->expects($this->once())
            ->method('getConfig')
            ->with('version_widgets')
            ->willReturn($config);
        $this->configRepository->expects($this->never())->method('save');
        $this->configFactory->expects($this->never())->method('create');

        $this->assertSame(7, $this->versionManagement->getCurrentVersion('widgets'));
    }

    public function testGetCurrentVersionSeedsDefaultWhenRecordMissing(): void
    {
        // No id -> a fresh record is created, seeded with the default version, and saved.
        $missing = $this->givenStoredConfig(null, '');
        $this->configRepository->method('getConfig')->with('version_widgets')->willReturn($missing);

        $created = $this->createMock(Config::class);
        $created->expects($this->once())->method('setName')->with('version_widgets')->willReturnSelf();
        $created->expects($this->once())->method('setValue')->with('0')->willReturnSelf();
        $created->method('getValue')->willReturn('0');
        $this->configFactory->expects($this->once())->method('create')->willReturn($created);

        $this->configRepository->expects($this->once())->method('save')->with($created);

        $this->assertSame(0, $this->versionManagement->getCurrentVersion('widgets'));
    }

    public function testGetCurrentVersionReturnsDefaultWhenRepositoryThrows(): void
    {
        // Storage failure (e.g. table not yet migrated) is swallowed -> default version.
        $this->configRepository->method('getConfig')
            ->willThrowException(new \RuntimeException('no such table'));
        $this->configRepository->expects($this->never())->method('save');

        $this->assertSame(0, $this->versionManagement->getCurrentVersion('widgets'));
    }

    public function testSetVersionUpdatesExistingRecord(): void
    {
        $existing = $this->givenStoredConfig(42, '1');
        $existing->expects($this->never())->method('setName');
        $existing->expects($this->once())->method('setValue')->with('9')->willReturnSelf();
        $this->configRepository->method('getConfig')->with('version_widgets')->willReturn($existing);

        $this->configFactory->expects($this->never())->method('create');
        $this->configRepository->expects($this->once())->method('save')->with($existing);

        $this->versionManagement->setVersion('widgets', 9);
    }

    public function testSetVersionCreatesRecordWhenMissing(): void
    {
        $missing = $this->givenStoredConfig(null, '');
        $this->configRepository->method('getConfig')->with('version_widgets')->willReturn($missing);

        $created = $this->createMock(Config::class);
        $created->expects($this->once())->method('setName')->with('version_widgets')->willReturnSelf();
        $created->expects($this->once())->method('setValue')->with('5')->willReturnSelf();
        $this->configFactory->expects($this->once())->method('create')->willReturn($created);

        $this->configRepository->expects($this->once())->method('save')->with($created);

        $this->versionManagement->setVersion('widgets', 5);
    }

    public function testSetVersionSwallowsRepositoryFailure(): void
    {
        // A storage exception must not propagate (first run before the DB table exists).
        $this->configRepository->method('getConfig')
            ->willThrowException(new \RuntimeException('no such table'));
        $this->configRepository->expects($this->never())->method('save');

        $this->versionManagement->setVersion('widgets', 5);

        $this->assertTrue(true);
    }

    public function testIsNewVersionTrueWhenStoredVersionIsLower(): void
    {
        $config = $this->givenStoredConfig(42, '3');
        $this->configRepository->method('getConfig')->with('version_widgets')->willReturn($config);

        $this->assertTrue($this->versionManagement->isNewVersion('widgets', 4));
    }

    public function testIsNewVersionFalseWhenStoredVersionEqual(): void
    {
        $config = $this->givenStoredConfig(42, '4');
        $this->configRepository->method('getConfig')->with('version_widgets')->willReturn($config);

        $this->assertFalse($this->versionManagement->isNewVersion('widgets', 4));
    }

    public function testIsNewVersionFalseWhenStoredVersionHigher(): void
    {
        $config = $this->givenStoredConfig(42, '10');
        $this->configRepository->method('getConfig')->with('version_widgets')->willReturn($config);

        $this->assertFalse($this->versionManagement->isNewVersion('widgets', 4));
    }

    /**
     * Builds a Config mock standing in for a stored row.
     *
     * @param int|null $id    null/0 means "no record yet"
     * @param string   $value the stored version string
     */
    private function givenStoredConfig(?int $id, string $value): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('getId')->willReturn($id);
        $config->method('getValue')->willReturn($value);

        return $config;
    }
}
