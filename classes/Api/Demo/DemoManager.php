<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Demo;

use Grav\Common\Config\Config;
use Grav\Common\Filesystem\Archiver;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
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
 *   api.pages.write  -> user/pages
 *   api.media.write  -> user/pages (page media) + user/media (site media)
 *   api.flex.*.write -> user/data
 * (accounts are never writable in demo mode, so never reset.)
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
            $permission === 'api.pages.write' => ['user://pages' => 'pages'],
            $permission === 'api.media.write' => ['user://pages' => 'pages', 'user://media' => 'media'],
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
        foreach ((array) $this->demoConfig('writable', []) as $permission) {
            foreach ($this->streamsForPermission((string) $permission) as $stream => $key) {
                $streams[$stream] = $key;
            }
        }

        $roots = [];
        foreach ($streams as $stream => $key) {
            $abs = $this->grav['locator']->findResource($stream, true);
            if ($abs && is_dir($abs)) {
                $roots[$key] = $abs;
            }
        }

        return $roots;
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
                $this->swapInto($abs, $restored);
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
    private function swapInto(string $live, string $restored): void
    {
        $parent = dirname($live);
        $suffix = bin2hex(random_bytes(6));
        $incoming = $parent . '/.demo-incoming-' . $suffix;
        $old = $parent . '/.demo-old-' . $suffix;

        // Build the replacement beside the live root.
        Folder::rcopy($restored, $incoming);

        try {
            if (!@rename($live, $old)) {
                throw new \RuntimeException("demo reset: could not move live root aside: {$live}");
            }
            if (!@rename($incoming, $live)) {
                @rename($old, $live); // roll back — never leave $live missing
                throw new \RuntimeException("demo reset: could not move restored content into place: {$live}");
            }
        } catch (\Throwable $e) {
            if (is_dir($incoming)) {
                Folder::delete($incoming);
            }
            throw $e;
        }

        Folder::delete($old);
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
                Folder::rcopy($abs, $staging . '/' . $key);
            }
            if (is_file($destZip)) {
                @unlink($destZip);
            }
            Archiver::create('zip')->setArchive($destZip)->compress($staging);
        } finally {
            Folder::delete($staging);
        }
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
     * Register a recurring reset job for installs that run `bin/grav scheduler`.
     * Belt-and-suspenders with the lazy per-request path — either alone keeps a
     * demo fresh; the lock serializes them if both fire.
     */
    public function onSchedulerInitialized(Event $event): void
    {
        if (!$this->demoConfig('reset_on_schedule', true) || !$this->baselineExists()) {
            return;
        }

        $interval = $this->resetIntervalMinutes();
        // Sub-hourly intervals map to a step expression; >=60 falls back to
        // hourly (the lazy path keeps exact timing regardless).
        $at = $interval >= 60 ? '0 * * * *' : '*/' . $interval . ' * * * *';

        $scheduler = $event['scheduler'];
        $job = $scheduler->addFunction(self::class . '::runScheduledReset', [], 'api-demo-reset');
        $job->at($at);
        $job->output('logs/api-demo-reset.out');
    }

    /**
     * Scheduler entry point. Registered as a `Class::method` string (mirroring
     * Backups::backup) so the scheduler can invoke it in a fresh process.
     */
    public static function runScheduledReset(): void
    {
        $grav = Grav::instance();
        (new self($grav, $grav['config']))->reset(false);
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
