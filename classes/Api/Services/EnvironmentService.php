<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;

/**
 * Resolves environment folders for config writes.
 *
 * The base write target is always user/config/. Named environments are
 * resolved through Grav's environment:// stream first, then through the
 * configured GRAV_ENVIRONMENTS_PATH and the legacy user/env/<name> and
 * user/<name> layouts. We never auto-create env folders during resolution —
 * they must be opted into via the environments API.
 */
class EnvironmentService
{
    private const RESERVED_USER_DIRS = [
        'accounts', 'blueprints', 'config', 'data', 'env',
        'images', 'languages', 'media', 'pages', 'plugins', 'themes',
    ];

    /**
     * Names the admin uses as the "base / no overlay" sentinel. The admin-next
     * environment switcher maps its base ("Default") selection to the env name
     * `default` for X-Grav-Environment, relying on there being no
     * user/env/default/ folder so Grav resolves config base-only (Setup empties
     * the environment:// stream for a non-existent env dir). Allowing an env
     * folder with one of these names would let an overlay silently shadow the
     * base-only view, so we refuse to create them.
     */
    private const RESERVED_ENV_NAMES = ['default', 'base'];

    public function __construct(private Grav $grav)
    {
    }

    /**
     * Absolute path to an env's config dir, or null if it doesn't exist.
     *
     * For the environment Grav booted under, the environment:// stream wins, so
     * GRAV_ENVIRONMENT_PATH and setup.php overrides are honored. Any other name
     * is looked up the way Grav's Setup would find it: GRAV_ENVIRONMENTS_PATH,
     * then user/env/<name>, then the legacy user/<name> layout.
     */
    public function envConfigRoot(string $name): ?string
    {
        if (!self::isValidName($name)) return null;

        $candidates = [];
        if ($name === $this->currentEnvironmentName()) {
            $candidates[] = $this->activeStreamConfigRoot();
        }
        $userRoot = $this->userRoot();
        foreach ([$this->environmentsRoot(), $userRoot ? $userRoot . '/env' : null, $userRoot] as $root) {
            if ($root !== null) $candidates[] = $root . '/' . $name . '/config';
        }

        foreach ($candidates as $dir) {
            if (is_string($dir) && is_dir($dir)) return $dir;
        }
        return null;
    }

    /**
     * List existing env folder names: GRAV_ENVIRONMENTS_PATH/* and user/env/*,
     * the booted env when its stream resolves, plus legacy user/<host>/ that
     * have a config/ subdir. Sorted, case-insensitive natural order.
     *
     * @return string[]
     */
    public function listEnvironments(): array
    {
        $names = [];
        $current = $this->currentEnvironmentName();
        if ($current !== null && $this->activeStreamConfigRoot() !== null) {
            $names[$current] = true;
        }

        $userRoot = $this->userRoot();
        foreach ([$this->environmentsRoot(), $userRoot ? $userRoot . '/env' : null] as $root) {
            if ($root === null || !is_dir($root)) continue;
            foreach (new \DirectoryIterator($root) as $item) {
                if ($item->isDot() || !$item->isDir() || !self::isValidName($item->getFilename())) continue;
                $names[$item->getFilename()] = true;
            }
        }

        if ($userRoot !== null) {
            foreach (new \DirectoryIterator($userRoot) as $item) {
                if ($item->isDot() || !$item->isDir()) continue;
                $n = $item->getFilename();
                if (in_array($n, self::RESERVED_USER_DIRS, true) || str_starts_with($n, '.')) continue;
                if (is_dir($item->getPathname() . '/config')) {
                    $names[$n] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /**
     * The environment Grav is currently loading config under, if any, AND only
     * when that env has a config dir on disk. Used by the config-write path so
     * saves land where reads come from — otherwise an active env overlay can
     * silently shadow a write to base.
     *
     * The env Grav actually booted its overlay under (`Setup::$environment`) is
     * authoritative. Behind a reverse proxy that is the REAL connection host —
     * e.g. `localhost` via `SERVER_NAME` — captured at boot, whereas
     * `$uri->environment()` reflects the FORWARDED host (e.g.
     * `translations.rhuk.net`) and so names an env whose overlay was never
     * loaded. We therefore trust the booted env first: if it has a config dir
     * that overlay is live, so return it; if it doesn't, no overlay is active
     * and base is correct (return null) — we must NOT fall through to a
     * forwarded-host env that isn't actually loaded. The Uri is consulted only
     * when the booted env is unknown (non-standard bootstrap, or unit tests).
     *
     * Returns null when no env is active, the env name is malformed, or the
     * active env has no config dir (in which case base writes are correct).
     */
    public function activeEnvironment(): ?string
    {
        $booted = $this->bootedEnvironment();
        if ($booted !== null) {
            return $this->envConfigRoot($booted) !== null ? $booted : null;
        }

        $name = $this->uriEnvironment();
        if ($name === null) {
            return null;
        }

        return $this->envConfigRoot($name) !== null ? $name : null;
    }

    /**
     * The environment Grav resolved at boot (`Setup::$environment`), normalized.
     * This is the env whose config overlay is actually loaded for the request.
     * Null when the static is unset/malformed or Grav core isn't available.
     */
    private function bootedEnvironment(): ?string
    {
        if (!class_exists(\Grav\Common\Config\Setup::class)) {
            return null;
        }

        $name = \Grav\Common\Config\Setup::$environment;
        return is_string($name) && $name !== '' && self::isValidName($name) ? $name : null;
    }

    /**
     * The environment derived from the Grav Uri service (the request host, with
     * forwarded-host handling applied). Defensive fallback only — see
     * {@see activeEnvironment()}.
     */
    private function uriEnvironment(): ?string
    {
        $uri = $this->grav['uri'] ?? null;
        if (!is_object($uri) || !method_exists($uri, 'environment')) {
            return null;
        }

        $name = $uri->environment();
        return is_string($name) && $name !== '' && self::isValidName($name) ? $name : null;
    }

    public function envHasOverrides(string $name): bool
    {
        $root = $this->envConfigRoot($name);
        if ($root === null) return false;
        foreach (new \FilesystemIterator($root) as $_) {
            return true;
        }
        return false;
    }

    /**
     * Create a new env/<name>/config/ folder. Returns the created config dir.
     * Throws \InvalidArgumentException on invalid names and \RuntimeException on fs failure.
     */
    public function createEnvironment(string $name): string
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException("Invalid environment name '{$name}'.");
        }
        if (in_array(strtolower($name), self::RESERVED_ENV_NAMES, true)) {
            throw new \InvalidArgumentException("Environment name '{$name}' is reserved for the base configuration.");
        }
        if (in_array($name, $this->listEnvironments(), true)) {
            throw new \InvalidArgumentException("Environment '{$name}' already exists.");
        }

        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new \RuntimeException('user:// path not resolvable.');
        }

        $environmentsRoot = $this->environmentsRoot() ?? ($userRoot . '/env');
        $configDir = $environmentsRoot . '/' . $name . '/config';
        if (!mkdir($configDir, 0775, true) && !is_dir($configDir)) {
            throw new \RuntimeException("Failed to create environment directory: {$configDir}");
        }
        return $configDir;
    }

    /**
     * Delete an environment folder and everything under it.
     *
     * Refuses to act on legacy user/<name>/ layouts (Grav 1.6 fallback) because
     * those directory names overlap freely with user-managed paths, so removing
     * them carries too much blast radius. Operators must clean those up by hand.
     * Refuses to delete the env Grav resolved for the current request so the
     * running session can't have its config yanked out from under it.
     *
     * Throws \InvalidArgumentException on validation failures, \OutOfBoundsException
     * when no user/env/<name>/ folder exists, and \RuntimeException on filesystem
     * failures.
     */
    public function deleteEnvironment(string $name): void
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException("Invalid environment name '{$name}'.");
        }
        if ($name === $this->activeEnvironment()) {
            throw new \InvalidArgumentException(
                "Cannot delete environment '{$name}': it is the active environment for this request."
            );
        }

        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new \RuntimeException('user:// path not resolvable.');
        }

        $configDir = $this->envConfigRoot($name);
        $legacyDir = $userRoot . '/' . $name;
        if ($configDir === null) {
            // OutOfBounds (not InvalidArgument) so the controller can answer 404
            // for a well-formed name that simply isn't there.
            throw new \OutOfBoundsException("Environment '{$name}' does not exist.");
        }

        $environmentDir = dirname($configDir);
        if (realpath($environmentDir) === realpath($legacyDir)) {
            throw new \InvalidArgumentException(
                "Environment '{$name}' uses the legacy user/{$name}/ layout. "
                . "Remove it manually so unrelated files are not deleted."
            );
        }

        // Guard against symlink escape: the resolved path must still live under
        // either Grav's configured common environment root or the historical
        // user/env root. If something has replaced an environment directory with
        // a symlink pointing elsewhere, refuse rather than recursively delete
        // outside an authorized tree.
        $real = realpath($environmentDir);
        $allowedRoots = array_filter([
            $this->environmentsRoot(),
            $userRoot . '/env',
        ]);
        $withinAllowedRoot = false;
        if ($real !== false) {
            foreach ($allowedRoots as $allowedRoot) {
                $rootReal = realpath($allowedRoot);
                if ($rootReal !== false && str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                    $withinAllowedRoot = true;
                    break;
                }
            }
        }
        if (!$withinAllowedRoot) {
            throw new \RuntimeException("Refusing to delete '{$environmentDir}': path resolves outside an authorized environment root.");
        }

        self::rmrf($real);
    }

    public static function isValidName(string $name): bool
    {
        return $name !== '' && (bool)preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $name);
    }

    /**
     * Whether a name is the admin's base/"no overlay" sentinel (`default` /
     * `base`). Such names are refused as env folders, and the config write path
     * treats them as a base (user/config) write target.
     */
    public static function isReservedName(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED_ENV_NAMES, true);
    }

    private static function rmrf(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }

    private function userRoot(): ?string
    {
        $root = $this->grav['locator']->findResource('user://', true);
        return $root !== false && is_string($root) ? $root : null;
    }


    /** The environment Grav booted with (the Uri only when that is unknown, e.g. in unit tests). */
    private function currentEnvironmentName(): ?string
    {
        return $this->bootedEnvironment() ?? $this->uriEnvironment();
    }

    /**
     * environment://config for the booted env, or null when it resolves to
     * nothing or to the base user/config (an override that points the stream
     * at user:// must not turn base writes into "env" writes).
     */
    private function activeStreamConfigRoot(): ?string
    {
        $path = $this->grav['locator']->findResource('environment://config', true);
        if (!is_string($path) || !is_dir($path)) return null;

        $userRoot = $this->userRoot();
        if ($userRoot !== null && realpath($path) === realpath($userRoot . '/config')) return null;
        return rtrim($path, '/\\');
    }

    /**
     * Grav's common environments folder (GRAV_ENVIRONMENTS_PATH), resolved the
     * way Setup::__construct() does: constant first, then the server env; a
     * stream through the locator, a relative path from GRAV_WEBROOT. Null when
     * unset or missing on disk. Server config only, never request input.
     */
    private function environmentsRoot(): ?string
    {
        $path = defined('GRAV_ENVIRONMENTS_PATH') ? GRAV_ENVIRONMENTS_PATH
            : ($_SERVER['GRAV_ENVIRONMENTS_PATH'] ?? $_ENV['GRAV_ENVIRONMENTS_PATH'] ?? getenv('GRAV_ENVIRONMENTS_PATH'));
        if (!is_string($path) || ($path = trim($path)) === '') return null;

        if (str_contains($path, '://')) {
            $path = $this->grav['locator']->findResource(rtrim($path, '/'), true, true);
        } elseif (!str_starts_with($path, '/') && !preg_match('/^[a-z]:[\\\\\/]/i', $path)) {
            $path = (defined('GRAV_WEBROOT') ? GRAV_WEBROOT : GRAV_ROOT) . '/' . $path;
        }

        return is_string($path) && is_dir($path) ? rtrim($path, '/\\') : null;
    }
}
