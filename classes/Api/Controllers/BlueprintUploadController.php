<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Page\Interfaces\PageInterface;
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

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $body = $request->getParsedBody() ?? [];
        $destination = is_array($body) ? (string)($body['destination'] ?? '') : '';
        $scope = is_array($body) ? (string)($body['scope'] ?? '') : '';

        if ($destination === '') {
            throw new ValidationException('destination is required.');
        }

        $targetDir = $this->resolveDestination($destination, $scope);

        $files = $this->flattenUploadedFiles($request->getUploadedFiles());
        if ($files === []) {
            throw new ValidationException('No file was uploaded.');
        }

        if (!is_dir($targetDir)) {
            Folder::create($targetDir);
        }

        $saved = [];
        foreach ($files as $file) {
            $saved[] = $this->processUploadedFile($file, $targetDir);
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
    private function resolveDestination(string $destination, string $scope): string
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
            $base = $this->resolveScopeRoot($scope);
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
    private function resolveScopeRoot(string $scope): ?string
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
            'users' => $name !== '' ? $this->resolveUserScope() : null,
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

    private function resolveUserScope(): ?string
    {
        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $accounts = $locator->findResource('account://', true, true);
        if (!$accounts) return null;
        // Avatars and similar live next to the account yaml, not in a per-user folder.
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

    private function processUploadedFile(UploadedFileInterface $file, string $targetDir): string
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

        if (
            $filename === ''
            || str_contains($filename, '..')
            || str_contains($filename, "\0")
            || str_starts_with($filename, '.')
        ) {
            throw new ValidationException("Invalid filename: '{$filename}'.");
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ValidationException('Uploaded file must have a file extension.');
        }

        $dangerous = array_map('strtolower', (array) $this->config->get('security.uploads_dangerous_extensions', []));
        if (in_array($extension, $dangerous, true)) {
            throw new ValidationException("File extension '.{$extension}' is not allowed for security reasons.");
        }

        $file->moveTo($targetDir . '/' . $filename);
        return $filename;
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
