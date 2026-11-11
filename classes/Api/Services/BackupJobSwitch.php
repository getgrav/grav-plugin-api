<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Inflector;

/**
 * Keeps a backup job's on/off switch in one place: the backup profile's
 * `schedule` flag.
 *
 * Core registers one scheduler job per backup profile, with the hyphenated
 * profile name as its id, and enables it from the profile's Scheduled Backup
 * switch alone. The Scheduler page shows the same job with an Enabled/Disabled
 * switch whose value is the `scheduler.status` map, so a backup job could end
 * up with a second, stale state there (classic admin wrote one for every job
 * when its Scheduler page was saved, see grav#4397).
 *
 * The api makes both switches edit the profile flag: a `scheduler.status`
 * entry for a backup job is never shown, a submitted one is moved onto the
 * profile, and none is ever stored.
 */
final class BackupJobSwitch
{
    /**
     * Scheduler job id for each backup profile, keyed by job id with the
     * profile's key as value. Profiles without a name have no job.
     *
     * @param array<mixed> $profiles `backups.profiles`
     * @return array<string, int|string>
     */
    public static function jobIds(array $profiles): array
    {
        $ids = [];
        foreach ($profiles as $key => $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $id = Inflector::hyphenize(trim((string) ($profile['name'] ?? '')));
            if ($id !== '' && !isset($ids[$id])) {
                $ids[$id] = $key;
            }
        }

        return $ids;
    }

    /**
     * The scheduler config without any `status` entry for a backup job.
     *
     * @param array<mixed> $scheduler scheduler config
     * @param array<mixed> $profiles `backups.profiles`
     * @return array<mixed>
     */
    public static function withoutBackupEntries(array $scheduler, array $profiles): array
    {
        if (!isset($scheduler['status']) || !is_array($scheduler['status'])) {
            return $scheduler;
        }

        $scheduler['status'] = array_diff_key($scheduler['status'], self::jobIds($profiles));

        return $scheduler;
    }

    /**
     * Copy the backup jobs' entries in a submitted `scheduler.status` map onto
     * their profiles' `schedule` flags.
     *
     * @param mixed $status submitted `scheduler.status`
     * @param array<mixed> $profiles `backups.profiles`
     * @return array{0: array<mixed>, 1: bool} the profiles, and whether any changed
     */
    public static function applyToProfiles(mixed $status, array $profiles): array
    {
        if (!is_array($status)) {
            return [$profiles, false];
        }

        $changed = false;
        foreach (self::jobIds($profiles) as $id => $key) {
            if (!array_key_exists($id, $status)) {
                continue;
            }
            $enabled = $status[$id] !== 'disabled';
            if ((bool) ($profiles[$key]['schedule'] ?? false) !== $enabled) {
                $profiles[$key]['schedule'] = $enabled;
                $changed = true;
            }
        }

        return [$profiles, $changed];
    }
}
