<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\PermissionResolver;

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
     */
    public function resolve(string $input, string $scope, ?UserInterface $caller = null): string
    {
        $locator = $this->locator();

        // `self@:subpath` / `@self:subpath` — relative to the blueprint owner.
        if (preg_match('/^(?:self@|@self)(?::(.*))?$/', $input, $m)) {
            $sub = $m[1] ?? '';
            if (str_contains($sub, '..')) {
                throw new ValidationException('Traversal not allowed in self@: subpath.');
            }
            $base = $this->resolveScopeRoot($scope, $caller);
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
    public function resolveScopeRoot(string $scope, ?UserInterface $caller = null): ?string
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
            'users' => $name !== '' ? $this->resolveUserScope($name, $caller) : null,
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
                'pages' => $name ? "pages/{$name}" : null,
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
     * Classify a resolved directory against the config-bearing dirs under
     * `user/`. Returns 'accounts', 'config', 'env', or null.
     *
     * Used by upload-side guards. Browse callers can ignore this since
     * Media::all() filters non-media files anyway and reading config is
     * harmless — but exposing the same method here keeps the security
     * logic centralized.
     */
    public function classifyTargetDir(string $absoluteDir): ?string
    {
        $userRoot = $this->userRoot();
        if ($userRoot === null) return null;

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

        $normalizedTarget = rtrim(str_replace('\\', '/', $absoluteDir), '/');
        $map = [
            'accounts' => $userRoot . '/accounts',
            'config'   => $userRoot . '/config',
            'env'      => $userRoot . '/env',
        ];
        foreach ($map as $label => $forbidden) {
            $normalizedForbidden = rtrim(str_replace('\\', '/', $forbidden), '/');
            if (
                $real === $forbidden
                || str_starts_with($real, $forbidden . '/')
                || $normalizedTarget === $normalizedForbidden
                || str_starts_with($normalizedTarget, $normalizedForbidden . '/')
            ) {
                return $label;
            }
        }
        return null;
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
        if ($real === false || (!str_starts_with($real, $userRoot . '/') && $real !== $userRoot)) {
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

    private function resolvePageScope(string $route): ?string
    {
        if ($route === '') return null;

        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        /** @var PageInterface|null $page */
        $page = $pages->find('/' . ltrim($route, '/'));
        return $page?->path() ?: null;
    }

    /**
     * Resolve `users/<username>` scope to the accounts directory.
     *
     * Tight gating: the caller must be editing their own account OR hold
     * `api.users.write`. Without this, any holder of `api.media.write` could
     * target other users' avatar slots — see GHSA-6xx2-m8wv-756h.
     */
    private function resolveUserScope(string $name, ?UserInterface $caller): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new ValidationException("Invalid users scope: '{$name}'.");
        }

        if ($caller === null) {
            throw new ForbiddenException("The 'users/{$name}' scope requires an authenticated caller.");
        }

        $isSelf = strcasecmp($caller->username, $name) === 0;
        $resolver = new PermissionResolver($this->grav['permissions']);
        $isSuper = (bool) $caller->get('access.api.super');
        $hasUsersWrite = (bool) $resolver->resolve($caller, 'api.users.write');

        if (!$isSelf && !$isSuper && !$hasUsersWrite) {
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
