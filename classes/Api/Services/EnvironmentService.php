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
     * The environment:// stream is authoritative for the environment Grav
     * booted under. This is important for GRAV_ENVIRONMENT_PATH and setup.php
     * stream overrides, where reconstructing user/env/<name> would point at a
     * different directory. For a non-active named target, only the explicit
     * common GRAV_ENVIRONMENTS_PATH is used before the historical fallbacks.
     */
    public function envConfigRoot(string $name): ?string
    {
        if (!self::isValidName($name)) return null;

        $current = $this->currentEnvironmentName();
        if ($current !== null && $current === $name) {
            $streamRoot = $this->environmentStreamConfigRoot();
            if ($streamRoot !== null) {
                return $streamRoot;
            }
        }

        $configuredRoot = $this->configuredEnvironmentConfigRoot($name);
        if ($configuredRoot !== null) {
            return $configuredRoot;
        }

        $userRoot = $this->userRoot();
        if ($userRoot === null) return null;

        foreach ([
            $userRoot . '/env/' . $name . '/config',
            $userRoot . '/' . $name . '/config',
        ] as $dir) {
            if (is_dir($dir)) return $dir;
        }
        return null;
    }

    /**
     * List existing env folder names — user/env/* plus legacy user/<host>/
     * that have a config/ subdir. Sorted, case-insensitive natural order.
     *
     * @return string[]
     */
    public function listEnvironments(): array
    {
        $names = [];
        $userRoot = $this->userRoot();

        // A custom common environment root is part of Grav's public setup
        // contract. Include it in discovery without assuming it lives under
        // user/env/.
        $configuredRoot = $this->configuredEnvironmentsRoot();
        if ($configuredRoot !== null) {
            $this->appendEnvironmentDirectories($names, $configuredRoot);
        }

        $current = $this->currentEnvironmentName();
        if ($current !== null && $this->environmentStreamConfigRoot() !== null) {
            $names[$current] = true;
        }

        if ($userRoot === null) {
            $names = array_keys($names);
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            return $names;
        }

        $envDir = $userRoot . '/env';
        if (is_dir($envDir)) {
            foreach (new \DirectoryIterator($envDir) as $item) {
                if ($item->isDot() || !$item->isDir()) continue;
                $names[$item->getFilename()] = true;
            }
        }

        foreach (new \DirectoryIterator($userRoot) as $item) {
            if ($item->isDot() || !$item->isDir()) continue;
            $n = $item->getFilename();
            if (in_array($n, self::RESERVED_USER_DIRS, true) || str_starts_with($n, '.')) continue;
            if (is_dir($item->getPathname() . '/config')) {
                $names[$n] = true;
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

        $environmentsRoot = $this->configuredEnvironmentsRoot() ?? ($userRoot . '/env');
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
        if ($this->samePath($environmentDir, $legacyDir)) {
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
            $this->configuredEnvironmentsRoot(),
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

    /**
     * The environment name Grav booted with, falling back to the URI only
     * when the core setup value is unavailable (notably in isolated tests).
     */
    private function currentEnvironmentName(): ?string
    {
        return $this->bootedEnvironment() ?? $this->uriEnvironment();
    }

    /**
     * Resolve the active environment through Grav's public stream API.
     */
    private function environmentStreamConfigRoot(): ?string
    {
        $locator = $this->grav['locator'] ?? null;
        if (!is_object($locator) || !method_exists($locator, 'findResource')) {
            return null;
        }

        $path = $locator->findResource('environment://config', true);
        return is_string($path) && is_dir($path) ? rtrim($path, '/\\') : null;
    }

    /**
     * Resolve a named environment below Grav's configured common environment
     * path. The value is controlled by the server/setup configuration, while
     * the environment name has already passed the API's strict validation.
     */
    private function configuredEnvironmentConfigRoot(string $name): ?string
    {
        $base = $this->configuredEnvironmentsPath();
        if ($base === null) {
            return null;
        }

        if ($this->isStreamUri($base)) {
            $path = $this->grav['locator']->findResource(
                rtrim($base, '/\\') . '/' . $name . '/config',
                true,
            );
        } else {
            $path = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'config';
        }

        return is_string($path) && is_dir($path) ? rtrim($path, '/\\') : null;
    }

    /**
     * Resolve the configured common environment root for discovery/creation.
     */
    private function configuredEnvironmentsRoot(): ?string
    {
        $base = $this->configuredEnvironmentsPath();
        if ($base === null) {
            return null;
        }

        if ($this->isStreamUri($base)) {
            $path = $this->grav['locator']->findResource(rtrim($base, '/\\'), true, true);
        } else {
            $path = $base;
        }

        return is_string($path) && is_dir($path) ? rtrim($path, '/\\') : null;
    }

    /**
     * Read the public Grav setting without hardcoding a repository-specific
     * directory. Constants take precedence over process environment values,
     * matching Grav's bootstrap behavior.
     */
    private function configuredEnvironmentsPath(): ?string
    {
        $path = defined('GRAV_ENVIRONMENTS_PATH')
            ? GRAV_ENVIRONMENTS_PATH
            : (getenv('GRAV_ENVIRONMENTS_PATH') ?: null);

        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        if ($this->isStreamUri($path)) {
            return trim($path);
        }

        if ($this->isAbsolutePath($path)) {
            return rtrim($path, '/\\');
        }

        // Grav documents relative values as paths from the web root. Resolve
        // them only below that root; never turn a user-supplied environment
        // name into a filesystem path.
        $root = defined('GRAV_ROOT')
            ? GRAV_ROOT
            : (defined('GRAV_WEBROOT') ? GRAV_WEBROOT : null);
        if (!is_string($root) || $root === '') {
            return null;
        }

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . trim($path, '/\\');
    }

    private static function isStreamUri(string $path): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('/^[a-z]:[\\\\\/]/i', $path);
    }

    private static function samePath(string $left, string $right): bool
    {
        return rtrim(str_replace('\\', '/', $left), '/') === rtrim(str_replace('\\', '/', $right), '/');
    }

    /**
     * @param array<string, bool> $names
     */
    private function appendEnvironmentDirectories(array &$names, string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        foreach (new \DirectoryIterator($root) as $item) {
            if ($item->isDot() || !$item->isDir() || !self::isValidName($item->getFilename())) {
                continue;
            }
            if (is_dir($item->getPathname() . '/config')) {
                $names[$item->getFilename()] = true;
            }
        }
    }
}
