<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Filesystem\Folder;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\BlueprintPathResolver;
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

    private ?BlueprintPathResolver $resolver = null;

    private function resolver(): BlueprintPathResolver
    {
        return $this->resolver ??= new BlueprintPathResolver($this->grav);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $body = $request->getParsedBody() ?? [];
        $destination = is_array($body) ? (string)($body['destination'] ?? '') : '';
        $scope = is_array($body) ? (string)($body['scope'] ?? '') : '';

        if ($destination === '') {
            throw new ValidationException('destination is required.');
        }
        $this->resolver()->assertSafe($destination);

        $targetDir = $this->resolver()->resolve($destination, $scope, $this->getUser($request));
        $this->guardConfigBearingTarget($targetDir);

        $files = $this->flattenUploadedFiles($request->getUploadedFiles());
        if ($files === []) {
            throw new ValidationException('No file was uploaded.');
        }

        if (!is_dir($targetDir)) {
            Folder::create($targetDir);
        }

        $isAccountsDir = $this->resolver()->classifyTargetDir($targetDir) === 'accounts';

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
        $logicalParent = $this->resolver()->logicalParent($destination, $scope);
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
     * Last-resort relative path: strip user-root prefix when we can, otherwise
     * surface the absolute path so at least the server knows what it wrote.
     */
    private function fallbackRelative(string $absolute): string
    {
        $userRoot = $this->resolver()->userRoot();
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
        if ($this->resolver()->classifyTargetDir($targetDir) === 'accounts') {
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

        $userRoot = $this->resolver()->userRoot();
        if ($userRoot === null) {
            throw new ValidationException('User root is not available.');
        }

        return $userRoot . '/' . $path;
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
        $classification = $this->resolver()->classifyTargetDir($absoluteDir);
        if ($classification === 'config' || $classification === 'env') {
            $where = $filename !== null ? "'{$filename}' under" : 'into';
            throw new ForbiddenException(
                "Uploads {$where} the '{$classification}' directory are not allowed via this endpoint."
            );
        }
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
