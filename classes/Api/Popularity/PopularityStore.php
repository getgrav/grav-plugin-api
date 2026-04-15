<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Popularity;

use Grav\Common\Grav;

/**
 * Single-file flat-JSON storage for page-view popularity data.
 *
 * Replaces admin-classic's four-JSON-file scheme (daily.json, monthly.json,
 * totals.json, visitors.json) with one combined `popularity.json` guarded
 * by an exclusive flock(). Wins vs. the old design:
 *
 *   - One file open + one lock per hit, vs. four uncoordinated read/writes
 *     that could race and silently corrupt each other.
 *   - `pages` (formerly `totals.json`) is capped at PAGES_CAP entries, so
 *     it can no longer grow unbounded with every URL ever visited.
 *   - ISO date keys (YYYY-MM-DD / YYYY-MM) sort lexicographically and are
 *     locale-stable, fixing the old `d-m-Y` ordering bug.
 *
 * On first construction in a site that still has the old four JSONs but no
 * combined file yet, the store imports them once and renames them to
 * `*.migrated` so nothing is lost and a re-run won't double-count.
 */
class PopularityStore
{
    private const SCHEMA_VERSION = 2;
    private const COMBINED_FILE = 'popularity.json';
    private const PAGES_CAP = 500;
    private const LEGACY_FILES = [
        'daily' => 'daily.json',
        'monthly' => 'monthly.json',
        'totals' => 'totals.json',
        'visitors' => 'visitors.json',
    ];

    private string $dataDir;
    private string $filePath;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? Grav::instance()['locator']
            ->findResource('log://popularity', true, true);
        $this->filePath = $this->dataDir . '/' . self::COMBINED_FILE;
    }

    /**
     * Record a single page hit. All four counters update inside one locked
     * read-modify-write cycle, so a concurrent hit can't tear the file or
     * lose updates.
     */
    public function recordHit(
        string $route,
        string $ipHash,
        ?int $now = null,
        int $dailyHistory = 30,
        int $monthlyHistory = 12,
        int $visitorHistory = 20,
    ): void {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $month = date('Y-m', $now);

        $this->withLock(function (array $data) use (
            $route, $ipHash, $now, $today, $month,
            $dailyHistory, $monthlyHistory, $visitorHistory,
        ): array {
            $data['daily'][$today] = ($data['daily'][$today] ?? 0) + 1;
            $data['monthly'][$month] = ($data['monthly'][$month] ?? 0) + 1;
            $data['pages'][$route] = ($data['pages'][$route] ?? 0) + 1;
            $data['visitors'][$ipHash] = $now;

            return $this->prune($data, $dailyHistory, $monthlyHistory, $visitorHistory);
        });
    }

    public function getDaily(int $limit = 365): array
    {
        $data = $this->read();
        $daily = $data['daily'] ?? [];
        ksort($daily);
        return array_slice($daily, -$limit, $limit, true);
    }

    public function getMonthly(int $limit = 24): array
    {
        $data = $this->read();
        $monthly = $data['monthly'] ?? [];
        ksort($monthly);
        return array_slice($monthly, -$limit, $limit, true);
    }

    public function getTopPages(int $limit = 10): array
    {
        $data = $this->read();
        $pages = $data['pages'] ?? [];
        arsort($pages);
        return array_slice($pages, 0, $limit, true);
    }

    public function getRecentVisitors(int $limit = 20): array
    {
        $data = $this->read();
        $visitors = $data['visitors'] ?? [];
        arsort($visitors);
        return array_slice($visitors, 0, $limit, true);
    }

    public function flush(): void
    {
        $this->withLock(fn() => $this->emptyData());
    }

    /**
     * Trim each section to its configured retention. Daily/monthly are
     * trimmed by date threshold (not just count) so an old, never-pruned
     * file gets cleaned up promptly. `pages` is capped to PAGES_CAP by
     * descending views — pages with no recent traffic naturally fall off.
     */
    private function prune(
        array $data,
        int $dailyHistory,
        int $monthlyHistory,
        int $visitorHistory,
    ): array {
        $cutDay = date('Y-m-d', strtotime("-{$dailyHistory} days"));
        $data['daily'] = array_filter(
            $data['daily'] ?? [],
            static fn($_, $k) => $k >= $cutDay,
            ARRAY_FILTER_USE_BOTH,
        );

        $cutMonth = date('Y-m', strtotime("-{$monthlyHistory} months"));
        $data['monthly'] = array_filter(
            $data['monthly'] ?? [],
            static fn($_, $k) => $k >= $cutMonth,
            ARRAY_FILTER_USE_BOTH,
        );

        $pages = $data['pages'] ?? [];
        if (count($pages) > self::PAGES_CAP) {
            arsort($pages);
            $pages = array_slice($pages, 0, self::PAGES_CAP, true);
        }
        $data['pages'] = $pages;

        $visitors = $data['visitors'] ?? [];
        if (count($visitors) > $visitorHistory) {
            arsort($visitors);
            $visitors = array_slice($visitors, 0, $visitorHistory, true);
        }
        $data['visitors'] = $visitors;

        return $data;
    }

    /**
     * Acquire exclusive lock, read current state (importing legacy files
     * the first time), apply the mutator, write atomically.
     */
    private function withLock(callable $mutator): void
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }

        $fp = fopen($this->filePath, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }

            $contents = stream_get_contents($fp);
            $data = $this->decodeOrMigrate($contents);
            $data = $mutator($data);
            $data['version'] = self::SCHEMA_VERSION;

            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function read(): array
    {
        if (!is_file($this->filePath)) {
            // Trigger migration if legacy files exist but combined doesn't
            if ($this->legacyFilesExist()) {
                $this->withLock(static fn(array $d) => $d);
            } else {
                return $this->emptyData();
            }
        }

        $fp = @fopen($this->filePath, 'r');
        if ($fp === false) {
            return $this->emptyData();
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return $this->emptyData();
            }
            $contents = stream_get_contents($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return $this->decodeOrMigrate($contents);
    }

    private function decodeOrMigrate(string $contents): array
    {
        $data = $contents !== '' ? json_decode($contents, true) : null;
        if (is_array($data) && isset($data['version'])) {
            return $this->ensureSections($data);
        }

        // Either empty file, malformed JSON, or unversioned legacy state.
        // Try to import legacy four-file data once.
        return $this->importLegacy();
    }

    private function importLegacy(): array
    {
        $data = $this->emptyData();
        $imported = false;

        foreach (self::LEGACY_FILES as $type => $name) {
            $path = $this->dataDir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            $legacy = $raw === false ? null : json_decode($raw, true);
            if (!is_array($legacy)) {
                @rename($path, $path . '.migrated');
                continue;
            }

            switch ($type) {
                case 'daily':
                    foreach ($legacy as $key => $count) {
                        $iso = self::convertDailyKey((string) $key);
                        if ($iso !== null) {
                            $data['daily'][$iso] = (int) $count;
                        }
                    }
                    break;
                case 'monthly':
                    foreach ($legacy as $key => $count) {
                        $iso = self::convertMonthlyKey((string) $key);
                        if ($iso !== null) {
                            $data['monthly'][$iso] = (int) $count;
                        }
                    }
                    break;
                case 'totals':
                    foreach ($legacy as $route => $count) {
                        $data['pages'][(string) $route] = (int) $count;
                    }
                    break;
                case 'visitors':
                    foreach ($legacy as $hash => $ts) {
                        $data['visitors'][(string) $hash] = (int) $ts;
                    }
                    break;
            }

            @rename($path, $path . '.migrated');
            $imported = true;
        }

        if ($imported) {
            ksort($data['daily']);
            ksort($data['monthly']);
        }

        return $data;
    }

    private function legacyFilesExist(): bool
    {
        foreach (self::LEGACY_FILES as $name) {
            if (is_file($this->dataDir . '/' . $name)) {
                return true;
            }
        }
        return false;
    }

    private function emptyData(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'daily' => [],
            'monthly' => [],
            'pages' => [],
            'visitors' => [],
        ];
    }

    private function ensureSections(array $data): array
    {
        return array_merge($this->emptyData(), $data);
    }

    private static function convertDailyKey(string $key): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
            return $key;
        }
        // Legacy d-m-Y → Y-m-d
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $key, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }

    private static function convertMonthlyKey(string $key): ?string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $key)) {
            return $key;
        }
        // Legacy m-Y → Y-m
        if (preg_match('/^(\d{2})-(\d{4})$/', $key, $m)) {
            return $m[2] . '-' . $m[1];
        }
        return null;
    }
}
