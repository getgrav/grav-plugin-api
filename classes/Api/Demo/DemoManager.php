<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Demo;

use Grav\Common\Config\Config;
use Grav\Common\Filesystem\Archiver;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Plugin\Api\Services\EnvironmentService;
use RocketTheme\Toolbox\Event\Event;

/**
 * Demo-mode reset/baseline engine.
 *
 * A super-admin captures a "known good" baseline of the demo-writable content
 * roots; the content then resets back to that baseline on a timer so a public
 * demo self-heals from whatever visitors do to it. Grav core has a backup
 * (archive) system but no restore, so the restore half is built here on top of
 * the hardened (but core-unused) ZipArchiver::extract().
 *
 * The whole engine is inert until a baseline exists — on a normal install
 * baselineExists() short-circuits every entry point, so shipping reset_on_request
 * / reset_on_schedule on by default is safe.
 *
 * Which roots are snapshot/reset is derived from the writable allowlist:
 *   api.pages.*   -> user/pages
 *   api.media.*   -> user/pages (page media) + user/media (site media)
 *   api.config.*  -> user/config, and each environment's config folder
 *   api.flex.*    -> user/data
 * plus user/plugins and user/themes with `include_code`, and any `user://`
 * folder listed in `extra_roots` (where a plugin keeps what visitors do on the
 * site itself, say). Accounts are never writable in demo mode, so they are only
 * reset when `extra_roots` lists them.
 *
 * Two things inside a root are never archived or restored, and survive every
 * reset as they are: user/data/api (API keys, the audit log, and this engine's
 * own baseline and state), and anything that is a symlink, which is managed
 * somewhere else. A root that is itself a symlink is reset at its target.
 */
class DemoManager
{
    public function __construct(
        private readonly Grav $grav,
        private readonly Config $config,
    ) {}

    /**
     * A demo-writable content root, as a `user://` stream mapped to the stable
     * top-level folder name it occupies inside the baseline archive.
     */
    private function streamsForPermission(string $permission): array
    {
        return match (true) {
            str_starts_with($permission, 'api.pages.') => ['user://pages' => 'pages'],
            str_starts_with($permission, 'api.media.') => ['user://pages' => 'pages', 'user://media' => 'media'],
            str_starts_with($permission, 'api.config.') => ['user://config' => 'config'],
            str_starts_with($permission, 'api.flex') => ['user://data' => 'data'],
            default => [],
        };
    }

    /**
     * Content roots a demo visitor can mutate, as `[archiveKey => absolutePath]`
     * for roots that actually exist on disk. Empty for a pure read-only demo
     * (nothing writable), which makes baseline/reset no-ops by design.
     *
     * @return array<string, string>
     */
    public function writableRoots(): array
    {
        $streams = [];
        foreach (DemoPolicy::expand((array) $this->demoConfig('writable', [])) as $permission) {
            foreach ($this->streamsForPermission($permission) as $stream => $key) {
                $streams[$stream] = $key;
            }
        }
        if ($this->demoConfig('include_code', false)) {
            $streams['user://plugins'] = 'plugins';
            $streams['user://themes'] = 'themes';
        }
        $extraKeys = [];
        foreach ((array) $this->demoConfig('extra_roots', []) as $stream) {
            $stream = rtrim(trim((string) $stream), '/');
            if (preg_match('#^user://([A-Za-z0-9_\-][A-Za-z0-9_\-/.]*)$#', $stream, $m) && !str_contains($m[1], '..')) {
                $streams[$stream] = $m[1];
                $extraKeys[$m[1]] = $stream;
            } elseif ($stream !== '') {
                $this->grav['log']->warning("api.demo: extra_roots entry '{$stream}' is not a user:// folder; ignored.");
            }
        }

        $roots = [];
        foreach ($streams as $stream => $key) {
            $abs = $this->grav['locator']->findResource($stream, true);
            $real = $abs ? realpath($abs) : false;
            if ($real && is_dir($real)) {
                $roots[$key] = $real;
            }
        }

        // A settings save lands in whichever environment the site booted
        // under, so with settings writable every environment's folder resets
        // too, whichever process (a request, the scheduler, the CLI) runs it.
        $settingsDirs = [];
        $config = $this->grav['locator']->findResource('user://config', true);
        if ($config && ($real = realpath($config))) {
            $settingsDirs['config'] = $real;
        }
        $environments = new EnvironmentService($this->grav);
        foreach ($environments->listEnvironments() as $name) {
            $dir = $environments->envConfigRoot((string) $name);
            $real = $dir ? realpath($dir) : false;
            if ($real && is_dir($real)) {
                $settingsDirs['environments/' . $name] = $real;
            }
        }
        if (isset($streams['user://config'])) {
            $roots += $settingsDirs;
        }

        // An extra root may not hold settings (they reset only through the
        // settings grants, which know what to leave alone) or sit at or inside
        // the API's own data, which a reset must never roll back.
        $apiData = $this->grav['locator']->findResource('user://data/api', true);
        $apiData = $apiData ? (realpath($apiData) ?: null) : null;
        foreach ($extraKeys as $key => $stream) {
            if (!isset($roots[$key]) || isset($settingsDirs[$key])) {
                continue;
            }
            $abs = $roots[$key];
            $refused = $apiData !== null && self::within($abs, $apiData);
            foreach ($settingsDirs as $dir) {
                $refused = $refused || self::within($abs, $dir) || self::within($dir, $abs);
            }
            if ($refused) {
                unset($roots[$key]);
                $this->grav['log']->warning("api.demo: extra_roots entry '{$stream}' overlaps settings or the API's own data; ignored.");
            }
        }

        // A root inside another root is already covered by it.
        $kept = [];
        foreach ($roots as $key => $abs) {
            foreach ($roots as $otherKey => $other) {
                if ($otherKey !== $key && str_starts_with($abs . '/', $other . '/') && strlen($other) < strlen($abs)) {
                    continue 2;
                }
            }
            $kept[$key] = $abs;
        }
        ksort($kept);

        return $kept;
    }

    // ---- Baseline capture ---------------------------------------------------

    /**
     * Snapshot the current content of the writable roots as the reset baseline
     * and (re)start the reset timer. Overwrites any previous baseline.
     */
    public function captureBaseline(): bool
    {
        $roots = $this->writableRoots();
        if ($roots === []) {
            return false;
        }

        return (bool) $this->withLock(true, function () use ($roots): void {
            $this->stageAndCompress($roots, $this->baselinePath());
            $state = $this->readState();
            $state['baseline_at'] = time();
            // Start the timer fresh so a just-captured baseline isn't instantly stale.
            $state['last_reset'] = time();
            $this->writeState($state);
        });
    }

    // ---- Reset --------------------------------------------------------------

    /**
     * Restore the writable roots to the captured baseline: safety-snapshot the
     * current state, extract the baseline over each root, then clear caches
     * (the programmatic equivalent of `bin/grav clear`).
     *
     * @param bool $blocking Wait for an in-flight reset (manual/CLI) vs skip if
     *                       one is already running (best-effort background).
     * @return bool Whether a reset actually ran.
     */
    public function reset(bool $blocking = true): bool
    {
        if (!$this->baselineExists()) {
            $this->grav['log']->warning('api.demo: reset requested but no baseline has been captured; skipping.');
            return false;
        }
        $roots = $this->writableRoots();
        if ($roots === []) {
            return false;
        }

        return (bool) $this->withLock($blocking, fn() => $this->doResetLocked($roots));
    }

    /**
     * Reset opportunistically on an incoming request when the content is stale.
     * Cheap to call on every request: the baseline/staleness checks short-circuit
     * fast, and a concurrent reset is skipped (non-blocking lock) rather than
     * queued. Never throws — a failed reset must not break the request.
     */
    public function maybeAutoReset(): void
    {
        if (!$this->demoConfig('reset_on_request', true)) {
            return;
        }
        $this->resetIfStale();
    }

    /**
     * Reset when the interval has passed, re-checked under the lock so a
     * request and the scheduler arriving together reset once. Skips rather
     * than waits when a reset is already running. Never throws.
     */
    private function resetIfStale(): void
    {
        if (!$this->baselineExists() || !$this->isStale()) {
            return;
        }
        $roots = $this->writableRoots();
        if ($roots === []) {
            return;
        }

        try {
            $this->withLock(false, function () use ($roots): void {
                // Re-check under the lock: another request may have just reset.
                if ($this->isStale()) {
                    $this->doResetLocked($roots);
                }
            });
        } catch (\Throwable $e) {
            $this->grav['log']->warning('api.demo: auto-reset failed: ' . $e->getMessage());
        }
    }

    /**
     * The actual restore, run while holding the reset lock.
     *
     * @param array<string, string> $roots
     */
    private function doResetLocked(array $roots): void
    {
        $this->captureSafetySnapshot($roots);

        $staging = $this->tempDir('restore');
        try {
            Archiver::create('zip')->setArchive($this->baselinePath())->extract($staging);
            foreach ($roots as $key => $abs) {
                $restored = $staging . '/' . $key;
                if (!is_dir($restored)) {
                    continue;
                }
                $this->swapInto($abs, $restored, $key);
            }
        } finally {
            Folder::delete($staging);
        }

        $this->grav['cache']->clearCache('all');

        $state = $this->readState();
        $state['last_reset'] = time();
        $this->writeState($state);
    }

    /**
     * Atomically replace the contents of $live with $restored.
     *
     * The replacement is staged as a sibling of $live (same filesystem, so
     * rename() is atomic) and swapped in with two renames. The live root is only
     * ever moved aside — never emptied in place — so a crash or a failed rename
     * leaves the original content intact (rolled back), rather than a
     * half-restored or empty root. This closes the read-window and the
     * "failed restore strands the site empty" gap.
     */
    private function swapInto(string $live, string $restored, string $key = ''): void
    {
        $parent = dirname($live);
        $suffix = bin2hex(random_bytes(6));
        $incoming = $parent . '/.demo-incoming-' . $suffix;
        $old = $parent . '/.demo-old-' . $suffix;

        // Build the replacement beside the live root.
        Folder::rcopy($restored, $incoming);

        // What a reset never touches moves across from the live root as it is,
        // symlinks included, in place of whatever the baseline holds there.
        $carried = [];
        try {
            foreach ($this->keptPaths($live, $key) as $relative) {
                $target = $incoming . '/' . $relative;
                $this->removePath($target);
                $source = $live . '/' . $relative;
                if (!file_exists($source) && !is_link($source)) {
                    continue;
                }
                if (!is_dir(dirname($target))) {
                    Folder::create(dirname($target));
                }
                if (!@rename($source, $target)) {
                    throw new \RuntimeException("demo reset: could not keep {$source}");
                }
                $carried[] = $relative;
            }

            if (!@rename($live, $old)) {
                throw new \RuntimeException("demo reset: could not move live root aside: {$live}");
            }
            if (!@rename($incoming, $live)) {
                @rename($old, $live); // roll back — never leave $live missing
                throw new \RuntimeException("demo reset: could not move restored content into place: {$live}");
            }
        } catch (\Throwable $e) {
            // Put back what was carried across, then drop the replacement.
            if (is_dir($live)) {
                foreach ($carried as $relative) {
                    @rename($incoming . '/' . $relative, $live . '/' . $relative);
                }
            }
            if (is_dir($incoming)) {
                $this->removeTree($incoming);
            }
            throw $e;
        }

        $this->removeTree($old);
    }

    /**
     * Archive the current writable-root content before a reset, so a bad
     * baseline is itself recoverable. Rolling, capped by keep_safety_snapshots.
     *
     * @param array<string, string> $roots
     */
    private function captureSafetySnapshot(array $roots): void
    {
        $keep = (int) $this->demoConfig('keep_safety_snapshots', 5);
        if ($keep <= 0) {
            return;
        }

        try {
            $this->stageAndCompress($roots, $this->demoDir() . '/safety--' . date('Ymd-His') . '.zip');
        } catch (\Throwable $e) {
            // A failed safety snapshot must not abort the reset itself.
            $this->grav['log']->warning('api.demo: safety snapshot failed: ' . $e->getMessage());
            return;
        }

        $snapshots = glob($this->demoDir() . '/safety--*.zip') ?: [];
        if (count($snapshots) > $keep) {
            rsort($snapshots); // lexical sort == newest-first for Ymd-His names
            foreach (array_slice($snapshots, $keep) as $old) {
                @unlink($old);
            }
        }
    }

    /**
     * Copy each writable root into a staging dir under its archive key, then
     * compress the staging dir once. Compressing a shared parent would give the
     * roots ambiguous relative paths; staging under fixed keys yields an
     * unambiguous `pages/…`, `media/…` archive that reset() maps straight back.
     *
     * @param array<string, string> $roots
     */
    private function stageAndCompress(array $roots, string $destZip): void
    {
        $staging = $this->tempDir('baseline');
        try {
            foreach ($roots as $key => $abs) {
                $this->copyTree($abs, $staging . '/' . $key, $this->keptPaths($abs, $key));
            }
            if (is_file($destZip)) {
                @unlink($destZip);
            }
            Archiver::create('zip')->setArchive($destZip)->compress($staging);
        } finally {
            Folder::delete($staging);
        }
    }

    /**
     * Paths under $root, relative to it, that a reset leaves exactly as they
     * are: the API plugin's own data folder, every symlink directly inside the
     * root or one level down (user/plugins/foo, user/pages/01.home), and in a
     * settings folder every file a demo account cannot write, so the owner's
     * own later changes there (to these demo settings, say) survive a reset.
     *
     * @return list<string>
     */
    private function keptPaths(string $root, string $key = ''): array
    {
        $kept = $key === 'config' || str_starts_with($key, 'environments/') ? $this->settingsKeptPaths($root) : [];

        $apiData = $this->grav['locator']->findResource('user://data/api', true);
        $apiData = $apiData ? (realpath($apiData) ?: $apiData) : null;
        if ($apiData && str_starts_with($apiData . '/', $root . '/') && $apiData !== $root) {
            $kept[] = substr($apiData, strlen($root) + 1);
        }

        foreach (glob($root . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
            $name = basename($child);
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_link($child)) {
                $kept[] = $name;
                continue;
            }
            if (is_dir($child)) {
                foreach (glob($child . '/{,.}*', GLOB_BRACE) ?: [] as $grandchild) {
                    $leaf = basename($grandchild);
                    if ($leaf !== '.' && $leaf !== '..' && is_link($grandchild)) {
                        $kept[] = $name . '/' . $leaf;
                    }
                }
            }
        }

        return self::outermost($kept);
    }

    /**
     * $paths without any path inside another one on the list. Keeping both
     * `plugins` and `plugins/api.yaml` would move the file across and then
     * replace the folder it was moved into.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function outermost(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        $out = [];
        foreach ($paths as $path) {
            foreach ($out as $kept) {
                if (str_starts_with($path . '/', $kept . '/')) {
                    continue 2;
                }
            }
            $out[] = $path;
        }

        return $out;
    }

    /**
     * Everything in a settings folder a demo account cannot write: whatever
     * is on disk now, and the locked and ungranted names even when they are
     * not, so a reset never brings back a copy the owner has since deleted.
     *
     * @return list<string>
     */
    private function settingsKeptPaths(string $root): array
    {
        $grants = DemoPolicy::expand((array) $this->demoConfig('writable', []));
        $granted = static fn (string $group): bool => in_array('api.config.' . $group, $grants, true);
        $groups = ['site.yaml' => 'site', 'plugins' => 'plugins', 'themes' => 'themes'];

        $paths = [];
        foreach (DemoPolicy::lockedConfigScopes() as $scope) {
            $paths[] = $scope . '.yaml';
        }
        foreach ($groups as $path => $group) {
            if (!$granted($group)) {
                $paths[] = $path;
            }
        }

        foreach (glob($root . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
            $name = basename($child);
            if ($name === '.' || $name === '..') {
                continue;
            }
            $group = $groups[$name] ?? null;
            if ($group === null || !$granted($group)) {
                $paths[] = $name;
                continue;
            }
            if ($group === 'site' || !is_dir($child)) {
                continue;
            }
            foreach (glob($child . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                $leaf = basename($file);
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $scope = $name . '/' . substr($leaf, 0, -5);
                if (!str_ends_with($leaf, '.yaml') || DemoPolicy::configGroup($scope) === null) {
                    $paths[] = $name . '/' . $leaf;
                }
            }
        }

        return $paths;
    }

    /**
     * Copy a folder, leaving out $skip (paths relative to $src) and never
     * following a symlink.
     *
     * @param list<string> $skip
     */
    private function copyTree(string $src, string $dest, array $skip, string $prefix = ''): void
    {
        if (!is_dir($dest)) {
            Folder::create($dest);
        }
        foreach (glob($src . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
            $name = basename($child);
            if ($name === '.' || $name === '..' || is_link($child)) {
                continue;
            }
            $relative = $prefix . $name;
            if (in_array($relative, $skip, true)) {
                continue;
            }
            if (is_dir($child)) {
                $this->copyTree($child, $dest . '/' . $name, $skip, $relative . '/');
            } else {
                copy($child, $dest . '/' . $name);
            }
        }
    }

    /** Whether $path is $dir or inside it. */
    private static function within(string $path, string $dir): bool
    {
        return str_starts_with($path . '/', rtrim($dir, '/') . '/');
    }

    /** Remove a file, a symlink (never what it points at) or a folder. */
    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            $this->removeTree($path);
        }
    }

    /**
     * Delete a folder without following symlinks inside it. A reset moves
     * symlinks out before the old root is deleted, but this is the one place a
     * mistake would delete files somewhere else, so it never relies on that.
     */
    private function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
            $name = basename($child);
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_link($child) || !is_dir($child)) {
                @unlink($child);
            } else {
                $this->removeTree($child);
            }
        }
        @rmdir($dir);
    }

    /**
     * What differs between the live roots and the baseline, by path inside the
     * archive (`pages/02.blog/item.md`). Compared by size and CRC32, so it is
     * cheap enough to run from the CLI on a site of any normal size.
     *
     * @return array{added: list<string>, changed: list<string>, removed: list<string>}|null
     *         null when there is no baseline
     */
    public function changes(): ?array
    {
        if (!$this->baselineExists()) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->baselinePath()) !== true) {
            throw new \RuntimeException('demo status: could not open the baseline archive.');
        }

        $archived = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false || str_ends_with($stat['name'], '/')) {
                continue;
            }
            $archived[$stat['name']] = [$stat['size'], $stat['crc']];
        }
        $zip->close();

        $live = [];
        foreach ($this->writableRoots() as $key => $abs) {
            $kept = $this->keptPaths($abs, $key);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($abs) + 1);
                foreach ($kept as $skip) {
                    if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                        continue 2;
                    }
                }
                $live[$key . '/' . $relative] = $file->getPathname();
            }
        }

        $added = $changed = [];
        foreach ($live as $name => $path) {
            if (!isset($archived[$name])) {
                $added[] = $name;
                continue;
            }
            [$size, $crc] = $archived[$name];
            if (filesize($path) !== $size || (int) hexdec(hash_file('crc32b', $path)) !== ($crc & 0xFFFFFFFF)) {
                $changed[] = $name;
            }
        }
        $removed = array_values(array_diff(array_keys($archived), array_keys($live)));

        sort($added);
        sort($changed);
        sort($removed);

        return ['added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    public function baselineCapturedAt(): ?int
    {
        $value = $this->readState()['baseline_at'] ?? null;
        return is_int($value) ? $value : null;
    }

    // ---- Timing / status ----------------------------------------------------

    public function baselineExists(): bool
    {
        return is_file($this->baselinePath());
    }

    public function resetIntervalMinutes(): int
    {
        $minutes = (int) $this->demoConfig('reset_interval', 30);
        return $minutes > 0 ? $minutes : 30;
    }

    public function lastResetAt(): ?int
    {
        $value = $this->readState()['last_reset'] ?? null;
        return is_int($value) ? $value : null;
    }

    /**
     * Seconds until the next reset is due, or null when the engine is dormant
     * (no baseline). 0 means a reset is due now.
     */
    public function secondsUntilReset(): ?int
    {
        if (!$this->baselineExists()) {
            return null;
        }
        $last = $this->lastResetAt();
        if ($last === null) {
            return 0;
        }
        return max(0, ($last + $this->resetIntervalMinutes() * 60) - time());
    }

    public function isStale(): bool
    {
        return $this->baselineExists() && $this->secondsUntilReset() === 0;
    }

    /**
     * Engine status for GET /demo/status (super-admin diagnostics + the "Set
     * baseline / Reset now" admin panel). Not per-account — the account-facing
     * demo_mode payload is built separately in AbstractApiController.
     *
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        return [
            'baseline_exists' => $this->baselineExists(),
            'baseline_at' => $this->baselineCapturedAt(),
            'writable' => array_values((array) $this->demoConfig('writable', [])),
            'roots' => array_keys($this->writableRoots()),
            'reset_interval' => $this->resetIntervalMinutes(),
            'reset_on_request' => (bool) $this->demoConfig('reset_on_request', true),
            'reset_on_schedule' => (bool) $this->demoConfig('reset_on_schedule', true),
            'last_reset' => $this->lastResetAt(),
            'seconds_until_reset' => $this->secondsUntilReset(),
        ];
    }

    // ---- Scheduler ----------------------------------------------------------

    /**
     * Register a reset job for installs that run `bin/grav scheduler`.
     * Belt-and-suspenders with the lazy per-request path — either alone keeps a
     * demo fresh; the lock serializes them if both fire.
     *
     * The job runs every minute and resets only once the interval has passed,
     * so any interval is kept exactly. A cron step expression cannot do that:
     * `*\/45` fires at :00 and :45.
     */
    public function onSchedulerInitialized(Event $event): void
    {
        if (!$this->demoConfig('reset_on_schedule', true) || !$this->baselineExists()) {
            return;
        }

        $scheduler = $event['scheduler'];
        $job = $scheduler->addFunction(self::class . '::runScheduledReset', [], 'api-demo-reset');
        $job->at('* * * * *');
        $job->output('logs/api-demo-reset.out');
    }

    /**
     * Scheduler entry point. Registered as a `Class::method` string (mirroring
     * Backups::backup) so the scheduler can invoke it in a fresh process.
     */
    public static function runScheduledReset(): void
    {
        $grav = Grav::instance();
        (new self($grav, $grav['config']))->resetIfStale();
    }

    // ---- Paths / state / lock ----------------------------------------------

    private function demoConfig(string $key, mixed $default = null): mixed
    {
        return $this->config->get('plugins.api.demo.' . $key, $default);
    }

    private function demoDir(): string
    {
        $dir = $this->grav['locator']->findResource('user://data/api', true, true) . '/demo';
        if (!is_dir($dir)) {
            Folder::create($dir);
        }
        return $dir;
    }

    public function baselinePath(): string
    {
        return $this->demoDir() . '/baseline.zip';
    }

    private function statePath(): string
    {
        return $this->demoDir() . '/state.json';
    }

    private function lockPath(): string
    {
        return $this->demoDir() . '/reset.lock';
    }

    private function tempDir(string $prefix): string
    {
        $base = $this->grav['locator']->findResource('cache://', true, true) . '/api/demo';
        if (!is_dir($base)) {
            Folder::create($base);
        }
        $dir = $base . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        Folder::create($dir);
        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        file_put_contents(
            $this->statePath(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Run $fn while holding an exclusive lock on the reset lockfile.
     *
     * Blocking (manual/CLI/baseline): waits out any in-flight reset. Non-blocking
     * (lazy auto-reset): returns false immediately if another process holds it.
     */
    private function withLock(bool $blocking, callable $fn): bool
    {
        $handle = fopen($this->lockPath(), 'c');
        if ($handle === false) {
            // Can't open the lockfile — for deliberate actions still run rather
            // than fail outright; for opportunistic resets, skip.
            if ($blocking) {
                $fn();
                return true;
            }
            return false;
        }

        try {
            if (!flock($handle, $blocking ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
                return false;
            }
            try {
                $fn();
                return true;
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
