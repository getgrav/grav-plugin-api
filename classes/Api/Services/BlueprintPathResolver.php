<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;

/**
 * Resolves blueprint-field path inputs (destination / folder) to an absolute
 * filesystem directory, mirroring admin-classic's logic in
 * AdminBaseController::taskFilesUpload / taskGetFilesInFolder.
 *
 * Inputs supported:
 *   - `self@:subpath`, `@self:subpath` — relative to the scope owner
 *     (plugins/<slug>, themes/<slug>, pages/<route>, users/<username>)
 *   - Grav streams: `user://`, `theme://`, `themes://`, `plugins://`,
 *     `account://`, `image://`, `asset://`, `page://`, etc.
 *   - Plain relative paths — resolved under `user/`, confined to it.
 *
 * Extracted from BlueprintUploadController so the same resolution is used
 * by the read-only browse endpoint (BlueprintFilesController). All security
 * gates that previously lived on the upload controller remain there; this
 * service is the path-resolution primitive only.
 */
class BlueprintPathResolver
{
    public function __construct(
        private readonly Grav $grav,
    ) {}

    /**
     * Reject traversal / null-byte / backslash strings before stream resolution.
     * Mirrors BlueprintUploadController::assertSafeDestination.
     */
    public function assertSafe(string $input): void
    {
        if (str_contains($input, "\0") || str_contains($input, '\\')) {
            throw new ValidationException('Invalid path.');
        }

        $path = $input;
        if (preg_match('/^(?:self@|@self)(?::(.*))?$/', $input, $m)) {
            $path = $m[1] ?? '';
        } elseif (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://(.*)$#', $input, $m)) {
            $path = $m[1] ?? '';
        }

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new ValidationException('Traversal not allowed.');
            }
        }
    }

    /**
     * Detect a `@self` / `self@` / `@self@` literal (no subpath). The browse
     * endpoint treats these specially — they mean "use the page's own media"
     * which is served via /pages/{route}/media, not a generic folder browse.
     */
    public function isSelfLiteral(string $input): bool
    {
        return in_array($input, ['@self', 'self@', '@self@', '@self/', 'self@/'], true);
    }

    /**
     * Resolve a blueprint destination/folder + scope to an absolute filesystem
     * directory.
     *
     * Streams and `self@:` owner roots are trusted as-is — Grav's resource
     * locator is the authority on where they point. Plain relative paths are
     * gated to stay under `user/`.
     *
     * @param UserInterface|null $caller Required to resolve `users/<username>` scope.
     * @param bool $callerMayWriteUsers Whether the caller may act on ANOTHER user's
     *   scope, already reduced by the API-key scope cap. Computed by the calling
     *   controller: this service never sees the request, so it must not re-derive
     *   authority from the account ACL (GHSA-435x-66r2-jwv2). Defaults to false so
     *   any future caller is closed by default.
     */
    public function resolve(
        string $input,
        string $scope,
        ?UserInterface $caller = null,
        bool $callerMayWriteUsers = false
    ): string {
        $locator = $this->locator();

        // `self@:subpath` / `@self:subpath` — relative to the blueprint owner.
        if (preg_match('/^(?:self@|@self)(?::(.*))?$/', $input, $m)) {
            $sub = $m[1] ?? '';
            if (str_contains($sub, '..')) {
                throw new ValidationException('Traversal not allowed in self@: subpath.');
            }
            $base = $this->resolveScopeRoot($scope, $caller, $callerMayWriteUsers);
            if ($base === null) {
                throw new ValidationException(
                    "Cannot resolve 'self@:' path: scope '{$scope}' is not a supported owner."
                );
            }
            return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
        }

        // Grav stream — user://, theme://, account://, etc.
        if ($locator->isStream($input)) {
            $resolved = $locator->findResource($input, true, true);
            if ($resolved === false || !is_string($resolved)) {
                throw new ValidationException("Stream not resolvable: '{$input}'.");
            }
            return $resolved;
        }

        // Plain path — must be relative to user root and stay inside it.
        if (str_starts_with($input, '/') || str_contains($input, '..')) {
            throw new ValidationException('Absolute or traversal paths are not allowed.');
        }
        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new ValidationException('User root is not available.');
        }
        return $this->assertInsideUserRoot($userRoot . '/' . $input);
    }

    /**
     * Map a scope (plugins/<slug>, themes/<slug>, pages/<route>, users/<username>)
     * to its filesystem root. Returns null for unsupported scope types.
     */
    public function resolveScopeRoot(string $scope, ?UserInterface $caller = null, bool $callerMayWriteUsers = false): ?string
    {
        if ($scope === '') return null;

        $parts = explode('/', $scope, 2);
        $type = $parts[0];
        $name = $parts[1] ?? '';

        $locator = $this->locator();

        return match ($type) {
            'plugins' => $this->resolveStreamOrNull($locator, 'plugins://', $name),
            'themes' => $this->resolveStreamOrNull($locator, 'themes://', $name),
            'pages' => $this->resolvePageScope($name),
            'users' => $name !== '' ? $this->resolveUserScope($name, $caller, $callerMayWriteUsers) : null,
            default => null,
        };
    }

    /**
     * Compute the Grav-root-relative directory path for a destination, used to
     * produce stable round-trip identifiers (returned by upload, accepted by
     * delete). Survives symlinks because it's derived from the logical input,
     * not the realpath.
     */
    public function logicalParent(string $destination, string $scope): ?string
    {
        // self@:sub — resolve relative to scope owner
        if (preg_match('/^(?:self@|@self)(?::(.*))?$/', $destination, $m)) {
            $sub = ltrim($m[1] ?? '', '/');
            [$type, $name] = array_pad(explode('/', $scope, 2), 2, '');
            $parent = match ($type) {
                'plugins' => $name ? "plugins/{$name}" : null,
                'themes' => $name ? "themes/{$name}" : null,
                'users' => 'accounts',
                'pages' => $name ? $this->pageLogicalDir($name) : null,
                default => null,
            };
            if ($parent === null) return null;
            return $sub === '' ? $parent : $parent . '/' . $sub;
        }

        // Known Grav streams that map 1:1 to user/ subdirs.
        $streamMap = [
            'user://' => '',
            'theme://' => $this->activeThemeDir(),
            'themes://' => 'themes',
            'plugins://' => 'plugins',
            'account://' => 'accounts',
            'image://' => 'images',
            'asset://' => 'assets',
            'page://' => 'pages',
        ];
        foreach ($streamMap as $prefix => $replace) {
            if ($replace !== null && str_starts_with($destination, $prefix)) {
                $rest = ltrim(substr($destination, strlen($prefix)), '/');
                $parts = array_filter([$replace, $rest], static fn($p) => $p !== '' && $p !== null);
                return implode('/', $parts);
            }
        }

        // Plain relative path — treated as user-rooted already.
        if (!str_starts_with($destination, '/') && !str_contains($destination, '..')) {
            return trim($destination, '/');
        }

        return null;
    }

    public function userRoot(): ?string
    {
        $locator = $this->locator();
        $root = $locator->findResource('user://', true, true);
        if ($root === false || !is_string($root)) return null;
        $real = realpath($root);
        return $real === false ? null : $real;
    }

    /**
     * Classify a resolved directory for the upload-side guards. Returns:
     *
     *   - 'accounts', 'config', 'env': the config-bearing dirs under `user/`;
     *   - 'templates': a folder Twig loads templates from (see isTemplateDir());
     *   - 'theme_or_plugin': anywhere inside `user/themes/` or `user/plugins/`;
     *   - 'outside': anywhere outside the user root;
     *   - null: anywhere else under `user/`.
     *
     * Each check looks at the logical path (what the locator or the caller
     * built) and at the realpath, since themes, plugins and other folders are
     * often symlinked in. The first match wins, in the order above, so a
     * template folder that also sits outside `user/` is 'templates'. A theme or
     * plugin that lives elsewhere and is symlinked in counts as
     * 'theme_or_plugin', not 'outside'. When the user root can't be resolved,
     * everything that isn't a template folder is 'outside'.
     *
     * Used by upload-side guards. Browse callers can ignore this since
     * Media::all() filters non-media files anyway and reading config is
     * harmless — but exposing the same method here keeps the security
     * logic centralized.
     */
    public function classifyTargetDir(string $absoluteDir): ?string
    {
        $userRoot = $this->userRoot();

        $probe = $absoluteDir;
        while ($probe !== '' && !file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            $probe = $parent;
        }
        $real = realpath($probe !== '' ? $probe : $absoluteDir);
        if ($real === false) {
            $real = $absoluteDir;
        }

        if ($userRoot !== null) {
            $normalizedTarget = rtrim(str_replace('\\', '/', $absoluteDir), '/');
            $map = [
                'accounts' => $userRoot . '/accounts',
                'config'   => $userRoot . '/config',
                'env'      => $userRoot . '/env',
            ];
            foreach ($map as $label => $forbidden) {
                $normalizedForbidden = rtrim(str_replace('\\', '/', $forbidden), '/');
                if (
                    PathContainment::isWithin($real, $forbidden, true)
                    || $normalizedTarget === $normalizedForbidden
                    || str_starts_with($normalizedTarget, $normalizedForbidden . '/')
                ) {
                    return $label;
                }
            }
        }

        // The not-yet-existing tail of the target, re-attached to the resolved
        // ancestor, so `templates/new-folder` is judged as the folder it will be.
        $realTarget = $real;
        if ($probe !== '' && $probe !== $absoluteDir && str_starts_with($absoluteDir, $probe)) {
            $realTarget = rtrim($real, '/') . substr($absoluteDir, strlen($probe));
        }

        $targets = array_values(array_unique(array_map(
            static fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/'),
            [$absoluteDir, $realTarget]
        )));
        $roots = $this->userRoots();

        if ($this->isTemplateDir($targets, $roots)) {
            return 'templates';
        }
        if ($this->isThemeOrPluginDir($targets, $roots)) {
            return 'theme_or_plugin';
        }
        foreach ($roots as $root) {
            foreach ($targets as $target) {
                if (PathContainment::isWithin($target, $root, true, '/')) {
                    return null;
                }
            }
        }

        return 'outside';
    }

    /**
     * The user root as resolved (realpath) and as the locator reports it,
     * with '/' separators, so a logical path and a realpath can each be
     * compared against the form they were built from.
     *
     * @return string[]
     */
    private function userRoots(): array
    {
        $roots = [];
        $resolved = $this->userRoot();
        if ($resolved !== null) {
            $roots[] = $resolved;
        }
        $raw = $this->locator()->findResource('user://', true);
        if (is_string($raw) && $raw !== '') {
            $roots[] = $raw;
        }

        return array_values(array_unique(array_map(
            static fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/'),
            $roots
        )));
    }

    /**
     * Whether a directory is, or sits inside, `user/themes/` or `user/plugins/`.
     *
     * Judged on the logical path against the user root, and on the realpath
     * against where the themes and plugins folders, and each installed theme
     * and plugin, really live (they are often symlinked in). Compared
     * case-insensitively: on a case-insensitive filesystem `Themes/` is the
     * same folder, and on a case-sensitive one this can only refuse more.
     *
     * @param string[] $targets Logical path and realpath, '/'-separated.
     * @param string[] $roots   User roots from userRoots().
     */
    private function isThemeOrPluginDir(array $targets, array $roots): bool
    {
        $targets = array_map('strtolower', $targets);

        foreach ($roots as $root) {
            $root = strtolower($root);
            foreach ($targets as $target) {
                if (!PathContainment::isWithin($target, $root, false, '/')) {
                    continue;
                }
                $relative = substr($target, strlen($root) + 1);
                if (preg_match('#^(?:themes|plugins)(?:/|$)#', $relative)) {
                    return true;
                }
            }
        }

        foreach ($this->packageRoots($roots) as $packageRoot) {
            foreach ($targets as $target) {
                if (PathContainment::isWithin($target, $packageRoot, true, '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Realpaths of the themes and plugins folders and of every installed theme
     * and plugin in them, lowercased and '/'-separated.
     *
     * @param string[] $userRoots
     * @return string[]
     */
    private function packageRoots(array $userRoots): array
    {
        $folders = [];
        foreach ($userRoots as $root) {
            $folders[] = $root . '/themes';
            $folders[] = $root . '/plugins';
        }
        $locator = $this->locator();
        if (method_exists($locator, 'findResources')) {
            foreach (['themes://', 'plugins://'] as $uri) {
                try {
                    $folders = array_merge($folders, (array) $locator->findResources($uri));
                } catch (\Throwable) {
                }
            }
        }

        $paths = [];
        foreach (array_unique($folders) as $folder) {
            if (!is_string($folder) || $folder === '' || !is_dir($folder)) {
                continue;
            }
            $paths[] = $folder;
            $paths = array_merge($paths, glob(rtrim($folder, '/') . '/*', GLOB_ONLYDIR) ?: []);
        }

        $normalized = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real !== false) {
                $normalized[] = strtolower(rtrim(str_replace('\\', '/', $real), '/'));
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Whether a directory is, or sits inside, a folder Twig loads templates
     * from. Twig loads any file there by name, whatever its extension, so the
     * whole folder counts, not just `.twig` files.
     *
     * Two checks, either one is enough:
     *   - the template paths actually registered: Twig's loader paths when
     *     Twig is initialized, the locator's `theme://templates` and
     *     `system://templates` regardless, and the extra site template folder
     *     the flex-objects and flex-directory plugins can be configured with;
     *   - by structure, any `templates` folder inside `user/themes/<name>/` or
     *     `user/plugins/<name>/`, judged on the logical path as well as the
     *     realpath, since themes and plugins are often symlinked in.
     *
     * Twig namespaces a theme or plugin registers on its own folders (a
     * theme's `images/` as `@images`, say) are not listed here: Twig is not
     * normally initialized during an API request, so they can't be seen. Those
     * folders are inside `user/themes/` or `user/plugins/`, which needs config
     * access anyway (see isThemeOrPluginDir()).
     *
     * Compared case-insensitively: on a case-insensitive filesystem
     * `Templates/` is the same folder.
     *
     * @param string[] $targets Logical path and realpath, '/'-separated.
     * @param string[] $roots   User roots from userRoots().
     */
    private function isTemplateDir(array $targets, array $roots): bool
    {
        $targets = array_values(array_unique(array_map('strtolower', $targets)));

        // Structural: user/(themes|plugins)/<name>/**/templates/**, against the
        // resolved user root and the locator's unresolved one.
        foreach ($roots as $root) {
            $root = strtolower($root);
            foreach ($targets as $target) {
                if (!PathContainment::isWithin($target, $root, false, '/')) {
                    continue;
                }
                $relative = substr($target, strlen($root) + 1);
                if (preg_match('#^(?:themes|plugins)/[^/]+/(?:.+/)?templates(?:/|$)#', $relative)) {
                    return true;
                }
            }
        }

        foreach ($this->templatePaths($roots) as $templatePath) {
            foreach ($targets as $target) {
                if (PathContainment::isWithin($target, $templatePath, true, '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every known template folder, normalized for comparison.
     *
     * @param string[] $userRoots
     * @return string[]
     */
    private function templatePaths(array $userRoots): array
    {
        $paths = [];

        if (isset($this->grav['twig'])) {
            try {
                $twig = $this->grav['twig'];
                if (is_array($twig->twig_paths ?? null)) {
                    $paths = array_merge($paths, $twig->twig_paths);
                }
                $loader = method_exists($twig, 'loader') ? $twig->loader() : null;
                if ($loader instanceof \Twig\Loader\FilesystemLoader) {
                    foreach ($loader->getNamespaces() as $namespace) {
                        $paths = array_merge($paths, $loader->getPaths($namespace));
                    }
                }
            } catch (\Throwable) {
                // Twig unavailable: the locator and structural checks still apply.
            }
        }

        $locator = $this->locator();
        if (method_exists($locator, 'findResources')) {
            foreach (['theme://templates', 'system://templates'] as $uri) {
                try {
                    $paths = array_merge($paths, (array) $locator->findResources($uri));
                } catch (\Throwable) {
                }
            }
        }

        // The extra site template folder flex-objects and flex-directory add
        // to Twig's paths when configured. It can point anywhere, including
        // a folder under `user/` that doesn't exist yet, so it's taken as
        // configured as well as resolved.
        try {
            $config = $this->grav['config'];
            foreach (['plugins.flex-objects.extra_site_twig_path', 'plugins.flex-directory.extra_site_twig_path'] as $key) {
                foreach ((array) $config->get($key) as $uri) {
                    if (!is_string($uri) || $uri === '') {
                        continue;
                    }
                    if ($locator->isStream($uri)) {
                        $resolved = $locator->findResource($uri, true, true);
                        if (is_string($resolved) && $resolved !== '') {
                            $paths[] = $resolved;
                        }
                    } elseif (str_starts_with($uri, '/')) {
                        $paths[] = $uri;
                    }
                }
            }
        } catch (\Throwable) {
        }

        // Existing templates folders of installed themes and plugins, so a
        // symlinked one is matched by where it really lives too.
        foreach ($userRoots as $root) {
            foreach (['themes', 'plugins'] as $kind) {
                $paths = array_merge($paths, glob($root . '/' . $kind . '/*/templates', GLOB_ONLYDIR) ?: []);
            }
        }

        // Realpath where the folder exists, the path as given where it doesn't.
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $real = realpath($path);
            $normalized[] = strtolower(rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/'));
        }

        return array_values(array_unique($normalized));
    }

    public function assertInsideUserRoot(string $path): string
    {
        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new ValidationException('User root is not available.');
        }
        $probe = $path;
        while ($probe !== '' && !file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            $probe = $parent;
        }
        $real = realpath($probe !== '' ? $probe : $userRoot);
        if ($real === false || !PathContainment::isWithin($real, $userRoot, true)) {
            throw new ValidationException('Path escapes the user directory.');
        }
        return rtrim($path, '/');
    }

    private function resolveStreamOrNull($locator, string $stream, string $name): ?string
    {
        if ($name === '') return null;
        $resolved = $locator->findResource($stream . $name, true, true);
        return is_string($resolved) ? $resolved : null;
    }

    /**
     * User-rooted folder of the page at `$route`, e.g. `pages/01.home` for the
     * route `home`. A page's route drops the folder's order prefix, so building
     * the path from the route pointed uploads at a folder that does not exist
     * (`pages/home`) while the file itself was written to `pages/01.home`.
     *
     * Uses the page's own relativePagePath(), the same value core's `self@`
     * token and classic admin store, which is not run through realpath so a
     * symlinked `user/pages` keeps its logical path. Falls back to the
     * route-based form when the page can't be found.
     */
    private function pageLogicalDir(string $route): string
    {
        $relative = str_replace('\\', '/', (string) ($this->findPage($route)?->relativePagePath() ?? ''));

        return str_starts_with($relative, 'user/')
            ? rtrim(substr($relative, 5), '/')
            : 'pages/' . trim($route, '/');
    }

    private function resolvePageScope(string $route): ?string
    {
        return $this->findPage($route)?->path() ?: null;
    }

    /**
     * @return PageInterface|null
     */
    private function findPage(string $route)
    {
        if ($route === '') return null;

        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        /** @var PageInterface|null $page */
        $page = $pages->find('/' . ltrim($route, '/'));
        return $page;
    }

    /**
     * Resolve `users/<username>` scope to the accounts directory.
     *
     * Tight gating: the caller must be editing their own account OR hold
     * `api.users.write` as capped by the presented credential's scopes. Without
     * this, any holder of `api.media.write` could target other users' avatar
     * slots — see GHSA-6xx2-m8wv-756h.
     *
     * The authority decision is made by the controller and handed in. Reading
     * `access.api.super` and `api.users.write` off the account here ignored the
     * API-key scope cap entirely, so a key scoped to `api.media.write` on a super
     * account cleared this gate (GHSA-435x-66r2-jwv2). The raw super read also
     * missed a group-granted `api.super`, the gap fixed for `isSuperAdmin()` in
     * 1.0.14 — routing through the controller picks that up for free.
     */
    private function resolveUserScope(string $name, ?UserInterface $caller, bool $callerMayWriteUsers = false): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new ValidationException("Invalid users scope: '{$name}'.");
        }

        if ($caller === null) {
            throw new ForbiddenException("The 'users/{$name}' scope requires an authenticated caller.");
        }

        $isSelf = strcasecmp($caller->username, $name) === 0;

        if (!$isSelf && !$callerMayWriteUsers) {
            throw new ForbiddenException(
                "The 'users/{$name}' scope requires editing your own account or holding the 'api.users.write' permission."
            );
        }

        $accounts = $this->locator()->findResource('account://', true, true);
        return is_string($accounts) ? $accounts : null;
    }

    private function activeThemeDir(): ?string
    {
        $theme = (string)($this->grav['config']->get('system.pages.theme') ?? '');
        return $theme === '' ? null : 'themes/' . $theme;
    }

    private function locator()
    {
        return $this->grav['locator'];
    }
}
