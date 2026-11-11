<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\BackupJobSwitch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupJobSwitch::class)]
class BackupJobSwitchTest extends TestCase
{
    private function profiles(): array
    {
        return [
            ['name' => 'Default Site Backup', 'schedule' => true, 'schedule_at' => '0 3 * * *'],
            ['name' => 'Pages Only', 'schedule' => false, 'schedule_at' => '0 4 * * *'],
            ['name' => '', 'schedule' => true],
            'not a profile',
        ];
    }

    public function testJobIdsAreTheHyphenatedProfileNames(): void
    {
        $this->assertSame(
            ['default-site-backup' => 0, 'pages-only' => 1],
            BackupJobSwitch::jobIds($this->profiles())
        );
    }

    public function testBackupEntriesAreRemovedAndOtherJobsKept(): void
    {
        $scheduler = [
            'status' => ['default-site-backup' => 'disabled', 'pages-only' => 'enabled', 'cache-purge' => 'disabled'],
            'custom_jobs' => [],
        ];

        $this->assertSame(
            ['status' => ['cache-purge' => 'disabled'], 'custom_jobs' => []],
            BackupJobSwitch::withoutBackupEntries($scheduler, $this->profiles())
        );
    }

    public function testConfigWithoutStatusIsUnchanged(): void
    {
        $this->assertSame(['enabled' => true], BackupJobSwitch::withoutBackupEntries(['enabled' => true], $this->profiles()));
    }

    public function testSubmittedSwitchesMoveOntoTheProfiles(): void
    {
        [$profiles, $changed] = BackupJobSwitch::applyToProfiles(
            ['default-site-backup' => 'disabled', 'pages-only' => 'enabled', 'cache-purge' => 'disabled'],
            $this->profiles()
        );

        $this->assertTrue($changed);
        $this->assertFalse($profiles[0]['schedule']);
        $this->assertTrue($profiles[1]['schedule']);
        $this->assertTrue($profiles[2]['schedule'], 'a profile with no job is left alone');
    }

    public function testMatchingSwitchesChangeNothing(): void
    {
        [$profiles, $changed] = BackupJobSwitch::applyToProfiles(
            ['default-site-backup' => 'enabled', 'pages-only' => 'disabled'],
            $this->profiles()
        );

        $this->assertFalse($changed);
        $this->assertSame($this->profiles(), $profiles);
    }

    public function testNoStatusChangesNothing(): void
    {
        $this->assertSame([$this->profiles(), false], BackupJobSwitch::applyToProfiles(null, $this->profiles()));
        $this->assertSame([$this->profiles(), false], BackupJobSwitch::applyToProfiles([], $this->profiles()));
    }
}
