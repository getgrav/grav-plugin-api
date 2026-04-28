<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Destination-aware file upload for blueprint-driven `type: file` fields.
 *
 * Mirrors admin-classic's `taskFilesUpload` semantics: the caller supplies a
 * blueprint `destination` (Grav stream, `self@:subpath`, or plain relative
 * path) plus the owning `scope` (plugins/<slug>, themes/<slug>, pages/<route>,
 * users/<username>) and the controller resolves the target directory using
 * Grav's locator, writes the file, and returns the saved path.
 *
 * Scope is required because `self@:` is relative to the blueprint's owner —
 * a theme's favicon field saves under `user/themes/<slug>/`, a plugin's logo
 * field under `user/plugins/<slug>/`, and so on. Without it we can't resolve
 * `self@:` safely.
 */
class BlueprintUploadController extends AbstractApiController
{
    private const MAX_UPLOAD_SIZE = 64 * 1_048_576; // 64 MB

    /**
     * Image-only allowlist for uploads landing in `user/accounts/` (avatars).
     *
     * `user/accounts/` doubles as the directory Grav reads as authoritative
     * account YAML, so allowing arbitrary extensions there is a privilege
     * escalation surface (GHSA-6xx2-m8wv-756h: a YAML file dropped here
     * becomes a fully functional account, including `access.api.super`).
     * The only legitimate blueprint-upload use case for this directory is
     * avatars, so the endpoint hard-restricts it to image extensions.
     */
    private const ACCOUNTS_IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp', 'ico',
    ];

    /**
     * Per-endpoint extension denylist on top of `security.uploads_dangerous_extensions`.
     *
     * Not all of these are "code" in the classic sense, but every one is a
     * file Grav (or a sibling tool) parses as authoritative configuration if
     * it lands in the right directory. Keeping them out of any blueprint-
     * upload target — not just `user/accounts/` — closes a class of bugs
     * where a future locator/scope edge case unexpectedly resolves into
     * `user/config/`, `user/env/<x>/config/`, or a plugin's own config dir.
     */
    private const FORBIDDEN_EXTENSIONS = [
        'yaml', 'yml',           // Grav account / config / blueprint
        'json',                  // generic config / data
        'twig',                  // template code
        'env',                   // env files
        'neon',                  // alt config format
        'lock',                  // composer/npm lockfiles
    ];

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $body = $request->getParsedBody() ?? [];
        $destination = is_array($body) ? (string)($body['destination'] ?? '') : '';
        $scope = is_array($body) ? (string)($body['scope'] ?? '') : '';

        if ($destination === '') {
            throw new ValidationException('destination is required.');
        }
        $this->assertSafeDestination($destination);

        $targetDir = $this->resolveDestination($destination, $scope, $request);
        $this->guardConfigBearingTarget($targetDir);

        $files = $this->flattenUploadedFiles($request->getUploadedFiles());
        if ($files === []) {
            throw new ValidationException('No file was uploaded.');
        }

        if (!is_dir($targetDir)) {
            Folder::create($targetDir);
        }

        $isAccountsDir = $this->classifyTargetDir($targetDir) === 'accounts';

        $saved = [];
        foreach ($files as $file) {
            $saved[] = $this->processUploadedFile($file, $targetDir, $isAccountsDir);
        }

        // Build a response payload describing each saved file in a Grav
        // file-field-compatible shape. `path` is the *logical* user-rooted
        // path (e.g. `user/themes/quark2/images/logo/file.png`) — derived
        // from the original destination+scope inputs, not the realpath, so
        // symlinked theme/plugin folders round-trip through a later delete
        // cleanly.
        $response = [];
        $logicalParent = $this->logicalParent($destination, $scope);
        foreach ($saved as $filename) {
            $absolute = $targetDir . '/' . $filename;
            $logical = $logicalParent !== null
                ? 'user/' . trim($logicalParent, '/') . '/' . $filename
                : $this->fallbackRelative($absolute);

            $response[] = [
                'name' => $filename,
                'path' => $logical,
                'size' => filesize($absolute) ?: 0,
                'type' => mime_content_type($absolute) ?: 'application/octet-stream',
                'url' => $this->buildPublicUrl($logical),
            ];
        }

        return ApiResponse::create($response, 201);
    }

    /**
     * Compute the Grav-root-relative directory path that a given destination
     * logically lives at, independent of any symlink resolution.
     *
     * For `theme://images/logo` with the active theme being `quark2`, the
     * logical parent is `themes/quark2/images/logo` — even if `user/themes/
     * quark2` is a symlink to a dev checkout elsewhere on disk. This matches
     * what a user would type if asked "where should this file live inside
     * the Grav install?" and gives us a stable identifier for deletes.
     *
     * Returns null only when the destination can't be mapped to a logical
     * location under `user/` (e.g. a foreign stream with no user-scoped
     * equivalent); callers should fall back to the realpath form.
     */
    private function logicalParent(string $destination, string $scope): ?string
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
                $rest = substr($destination, strlen($prefix));
                $rest = ltrim($rest, '/');
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

    private function activeThemeDir(): ?string
    {
        $theme = (string)($this->config->get('system.pages.theme') ?? '');
        return $theme === '' ? null : 'themes/' . $theme;
    }

    /**
     * Last-resort relative path: strip user-root prefix when we can, otherwise
     * surface the absolute path so at least the server knows what it wrote.
     */
    private function fallbackRelative(string $absolute): string
    {
        $userRoot = $this->userRoot();
        if ($userRoot !== null && str_starts_with($absolute, $userRoot . '/')) {
            return 'user/' . substr($absolute, strlen($userRoot) + 1);
        }
        return $absolute;
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $body = $this->getRequestBody($request);
        $path = (string)($body['path'] ?? '');

        if ($path === '') {
            throw new ValidationException('path is required.');
        }

        $absolute = $this->resolveDeletePath($path);
        $targetDir = dirname($absolute);
        $filename = basename($absolute);

        $this->guardConfigBearingTarget($targetDir, $filename);

        // Symmetric to the upload path: deletes targeting `user/accounts/` may
        // only act on image files (avatars). Without this gate, a holder of
        // `api.media.write` could `unlink` arbitrary account YAMLs.
        if ($this->classifyTargetDir($targetDir) === 'accounts') {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ACCOUNTS_IMAGE_EXTENSIONS, true)) {
                throw new ForbiddenException(
                    "Deletes under user/accounts/ are restricted to avatar image files."
                );
            }
        }
        $this->assertSafeExtension($filename, false);

        // Idempotent: a file that's already gone is indistinguishable from a
        // file we just deleted, so don't pollute the client with a 404 that
        // forces special-case handling. Anything non-file (directory,
        // symlink-to-elsewhere, etc.) still errors — those are genuine
        // misuses, not "already gone".
        if (!file_exists($absolute)) {
            return ApiResponse::noContent();
        }

        if (!is_file($absolute)) {
            throw new ValidationException('Target is not a regular file.');
        }

        unlink($absolute);

        // Clean up adjacent metadata if present.
        $meta = $absolute . '.meta.yaml';
        if (file_exists($meta)) {
            unlink($meta);
        }

        return ApiResponse::noContent();
    }

    /**
     * Reject traversal/null-byte destination strings before handing them to
     * Grav's stream locator. The locator is still responsible for resolving
     * streams and symlinks, but API input should never contain path-control
     * segments.
     */
    private function assertSafeDestination(string $destination): void
    {
        if (str_contains($destination, "\0") || str_contains($destination, '\\')) {
            throw new ValidationException('Invalid destination.');
        }

        $path = $destination;
        if (preg_match('/^(?:self@|@self)(?::(.*))?$/', $destination, $m)) {
            $path = $m[1] ?? '';
        } elseif (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://(.*)$#', $destination, $m)) {
            $path = $m[1] ?? '';
        }

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new ValidationException('Traversal not allowed in destination.');
            }
        }
    }

    /**
     * Resolve a blueprint `destination` + `scope` to an absolute filesystem
     * directory.
     *
     * Streams and `self@:` owner roots are trusted as-is — Grav's resource
     * locator is the authority on what those paths point at, and enforcing a
     * "must stay under user/" check against the resolved realpath breaks
     * perfectly valid setups where plugins/themes live in development
     * symlinks that land outside `user/`. Plain relative paths are still
     * strictly gated: they resolve from the user root and are rejected if
     * they try to escape via `..` or absolute prefixes.
     */
    private function resolveDestination(string $destination, string $scope, ServerRequestInterface $request): string
    {
        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];

        // `self@:subpath` or `@self:subpath` — relative to the blueprint owner.
        $selfMatch = preg_match('/^(?:self@|@self)(?::(.*))?$/', $destination, $m);
        if ($selfMatch) {
            $sub = $m[1] ?? '';
            if (str_contains($sub, '..')) {
                throw new ValidationException('Traversal not allowed in self@: subpath.');
            }
            $base = $this->resolveScopeRoot($scope, $request);
            if ($base === null) {
                throw new ValidationException(
                    "Cannot resolve 'self@:' destination: scope '{$scope}' is not a supported owner."
                );
            }
            return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
        }

        // Grav stream — user://, theme://, account://, etc. The locator
        // decides where these point; trust its resolution.
        if ($locator->isStream($destination)) {
            $resolved = $locator->findResource($destination, true, true);
            if ($resolved === false || !is_string($resolved)) {
                throw new ValidationException("Destination stream not resolvable: '{$destination}'.");
            }
            return $resolved;
        }

        // Plain path — must be relative to user root and stay inside it.
        if (str_starts_with($destination, '/') || str_contains($destination, '..')) {
            throw new ValidationException('Absolute or traversal paths are not allowed in destination.');
        }
        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new ValidationException('User root is not available.');
        }
        return $this->assertInsideUserRoot($userRoot . '/' . $destination);
    }

    /**
     * Map a scope (plugins/<slug>, themes/<slug>, pages/<route>, users/<username>)
     * to its filesystem root. Returns null for scopes that don't have a
     * natural `self@:` owner (e.g. `config/system`).
     */
    private function resolveScopeRoot(string $scope, ServerRequestInterface $request): ?string
    {
        if ($scope === '') return null;

        $parts = explode('/', $scope, 2);
        $type = $parts[0];
        $name = $parts[1] ?? '';

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];

        return match ($type) {
            'plugins' => $this->resolveStreamOrNull($locator, 'plugins://', $name),
            'themes' => $this->resolveStreamOrNull($locator, 'themes://', $name),
            'pages' => $this->resolvePageScope($name),
            'users' => $name !== '' ? $this->resolveUserScope($name, $request) : null,
            default => null,
        };
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
     * Avatars (the only legitimate use case for this scope) live next to the
     * account YAML in `user/accounts/`, not in a per-user subfolder. Because
     * this directory is the same one Grav reads as authoritative account
     * configuration, the scope must be tightly gated:
     *
     *   - `<username>` must match the Grav username pattern (no path tricks)
     *   - The caller must be editing their own account, OR hold
     *     `api.users.write` (the user-management permission)
     *
     * Without this gate, any holder of `api.media.write` could target any
     * other user's avatar slot — and combined with a directory-classification
     * miss, that's the GHSA-6xx2-m8wv-756h primitive. Per-extension filtering
     * happens later in `processUploadedFile()`; this method's job is to stop
     * cross-user writes at the scope-resolution layer.
     */
    private function resolveUserScope(string $name, ServerRequestInterface $request): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new ValidationException("Invalid users scope: '{$name}'.");
        }

        $caller = $this->getUser($request);
        $isSelf = strcasecmp($caller->username, $name) === 0;
        if (!$isSelf && !$this->isSuperAdmin($caller) && !$this->hasPermission($caller, 'api.users.write')) {
            throw new ForbiddenException(
                "The 'users/{$name}' scope requires editing your own account or holding the 'api.users.write' permission."
            );
        }

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $accounts = $locator->findResource('account://', true, true);
        if (!$accounts) return null;
        return is_string($accounts) ? $accounts : null;
    }

    /**
     * Resolve the `path` for a delete request.
     *
     * Clients send the same logical path we returned on upload (e.g.
     * `themes/quark2/images/logo/foo.png`), always relative to the user
     * root. No absolute paths and no `..` traversal are permitted on input —
     * that's what keeps the endpoint safe. Once the path is validated, we
     * join it to the user root and trust the resolved location even if it
     * passes through a Grav symlink (a common setup where `user/themes/X`
     * points at a dev checkout outside `user/`). The symlink is already part
     * of Grav's resource map; pretending it isn't would lock out valid
     * deletes on every non-trivial install.
     */
    private function resolveDeletePath(string $path): string
    {
        $path = ltrim($path, '/');
        // Allow both "themes/..." and "user/themes/..." inputs — the latter
        // is what upload returns when the destination lives under user/
        // directly (no symlink), so both forms round-trip.
        if (str_starts_with($path, 'user/')) {
            $path = substr($path, 5);
        }

        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new ValidationException('Traversal or null bytes not allowed in path.');
        }

        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new ValidationException('User root is not available.');
        }

        return $userRoot . '/' . $path;
    }

    private function assertInsideUserRoot(string $path): string
    {
        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new ValidationException('User root is not available.');
        }
        // If the path doesn't exist yet, validate the nearest existing parent.
        $probe = $path;
        while ($probe !== '' && !file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            $probe = $parent;
        }
        $real = realpath($probe !== '' ? $probe : $userRoot);
        if ($real === false || (!str_starts_with($real, $userRoot . '/') && $real !== $userRoot)) {
            throw new ValidationException('Destination escapes the user directory.');
        }
        return rtrim($path, '/');
    }

    private function userRoot(): ?string
    {
        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $root = $locator->findResource('user://', true, true);
        if ($root === false || !is_string($root)) return null;
        $real = realpath($root);
        return $real === false ? null : $real;
    }

    private function buildPublicUrl(string $relative): ?string
    {
        $uri = $this->grav['uri'];
        $base = method_exists($uri, 'rootUrl') ? $uri->rootUrl() : '';
        return rtrim($base, '/') . '/' . ltrim($relative, '/');
    }

    private function processUploadedFile(UploadedFileInterface $file, string $targetDir, bool $isAccountsDir): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('File upload failed.');
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_SIZE) {
            throw new ValidationException(
                sprintf('File exceeds maximum allowed size of %d MB.', self::MAX_UPLOAD_SIZE / 1_048_576)
            );
        }

        $originalName = $file->getClientFilename() ?? 'upload';
        $filename = basename($originalName);

        $this->assertSafeFilename($filename);
        $this->assertSafeExtension($filename, $isAccountsDir);

        $file->moveTo($targetDir . '/' . $filename);
        return $filename;
    }

    /**
     * Reject filenames that would escape the target dir or hide as a dotfile.
     */
    private function assertSafeFilename(string $filename): void
    {
        if (
            $filename === ''
            || str_contains($filename, '..')
            || str_contains($filename, "\0")
            || str_starts_with($filename, '.')
        ) {
            throw new ValidationException("Invalid filename: '{$filename}'.");
        }
    }

    /**
     * Apply layered extension policy:
     *
     *   1. `security.uploads_dangerous_extensions` (Grav-wide denylist: php, js, exe, ...)
     *   2. Per-endpoint denylist for known-config formats (yaml, json, twig, ...)
     *   3. If target is `user/accounts/`, restrict to image extensions only —
     *      the directory doubles as Grav's authoritative account store, so
     *      anything non-image is a privesc surface (GHSA-6xx2-m8wv-756h).
     *
     * Returns the lowercased extension for callers that want it.
     */
    private function assertSafeExtension(string $filename, bool $isAccountsDir): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ValidationException('Uploaded file must have a file extension.');
        }

        $dangerous = array_map('strtolower', (array) $this->config->get('security.uploads_dangerous_extensions', []));
        if (in_array($extension, $dangerous, true)) {
            throw new ValidationException("File extension '.{$extension}' is not allowed for security reasons.");
        }

        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new ValidationException("File extension '.{$extension}' is not allowed for blueprint uploads.");
        }

        if ($isAccountsDir && !in_array($extension, self::ACCOUNTS_IMAGE_EXTENSIONS, true)) {
            throw new ValidationException(
                "Only image files (" . implode(', ', self::ACCOUNTS_IMAGE_EXTENSIONS) . ") may be uploaded to user/accounts/."
            );
        }

        return $extension;
    }

    /**
     * Hard-deny writes resolving to directories that Grav reads as
     * authoritative configuration: `user/config/` and any `user/env/.../config/`.
     * `user/accounts/` is allowed (avatars) but extension-restricted in
     * `assertSafeExtension()`.
     *
     * `$filename` is optional — pass it for delete-path checks (where we
     * have the final filename) so the error message can name the target;
     * for upload checks the per-file extension policy fires later anyway.
     */
    private function guardConfigBearingTarget(string $absoluteDir, ?string $filename = null): void
    {
        $classification = $this->classifyTargetDir($absoluteDir);
        if ($classification === 'config' || $classification === 'env') {
            $where = $filename !== null ? "'{$filename}' under" : 'into';
            throw new ForbiddenException(
                "Uploads {$where} the '{$classification}' directory are not allowed via this endpoint."
            );
        }
    }

    /**
     * Classify a resolved absolute directory against the config-bearing
     * directories under `user/`. Returns `'accounts'`, `'config'`, `'env'`,
     * or null for "anything else".
     *
     * Uses `realpath` of the nearest existing parent so the classification
     * survives symlinks (common in dev setups where `user/themes/<x>` points
     * elsewhere on disk) without requiring the target to already exist.
     */
    private function classifyTargetDir(string $absoluteDir): ?string
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

    /**
     * @param array<UploadedFileInterface|array> $files
     * @return UploadedFileInterface[]
     */
    private function flattenUploadedFiles(array $files): array
    {
        $result = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFileInterface) {
                $result[] = $file;
            } elseif (is_array($file)) {
                $result = array_merge($result, $this->flattenUploadedFiles($file));
            }
        }
        return $result;
    }
}
