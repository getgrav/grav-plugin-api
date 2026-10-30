<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Security;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\BlueprintLoader;
use Grav\Plugin\Api\Services\BlueprintPathResolver;
use Grav\Plugin\Api\Services\UploadFieldSettings;
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
     * Nothing lifts these, not even a field's `allow_extensions`.
     *
     * Not all of these are "code" in the classic sense, but every one is a
     * file Grav (or a sibling tool) parses as authoritative configuration if
     * it lands in the right directory. Keeping them out of any blueprint-
     * upload target — not just `user/accounts/` — closes a class of bugs
     * where a future locator/scope edge case unexpectedly resolves into
     * `user/config/`, `user/env/<x>/config/`, or a plugin's own config dir.
     */
    private const CONFIG_EXTENSIONS = [
        'yaml', 'yml',           // Grav account / config / blueprint
        'json',                  // generic config / data
        'twig',                  // template code
        'env',                   // env files
        'neon',                  // alt config format
        'lock',                  // composer/npm lockfiles
    ];

    /**
     * Page content and stylesheets: refused by default, because anyone with
     * `api.media.write` could otherwise write or delete them anywhere under
     * `user/`. A blueprint `type: file` field may lift these, and only these,
     * with `allow_extensions`, which the server reads from the blueprint itself
     * (see liftedExtensions()), never from the request.
     */
    private const CONTENT_EXTENSIONS = [
        'md', 'markdown',        // page content; Grav renders it as a page
        'css', 'scss', 'sass', 'less', // theme and plugin styling
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

        $targetDir = $this->resolver()->resolve(
            $destination,
            $scope,
            $this->getUser($request),
            $this->mayWriteUsersScope($request)
        );
        $isAccountsDir = $this->guardTargetDir($request, $targetDir) === 'accounts';

        $files = $this->flattenUploadedFiles($request->getUploadedFiles());
        if ($files === []) {
            throw new ValidationException('No file was uploaded.');
        }

        if (!is_dir($targetDir)) {
            Folder::create($targetDir);
        }

        // A field's `allow_extensions`, read from the scope's own blueprint.
        // Only looked up when a file actually carries a content/style extension.
        $lifted = [];
        foreach ($files as $file) {
            if ($this->carriesContentExtension(basename($file->getClientFilename() ?? ''))) {
                $lifted = $this->liftedExtensions(
                    is_array($body) ? (string) ($body['field'] ?? '') : '',
                    $scope,
                    $destination,
                    null
                );
                break;
            }
        }

        // Per-field upload settings (random_name, avoid_overwriting, accept,
        // filesize) ride in on the same body as destination/scope.
        $settings = is_array($body) ? UploadFieldSettings::fromParams($body) : UploadFieldSettings::none();

        $saved = [];
        foreach ($files as $file) {
            $saved[] = $this->processUploadedFile($file, $targetDir, $isAccountsDir, $settings, $lifted);
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

        $classification = $this->guardTargetDir($request, $targetDir, $filename);

        // Symmetric to the upload path: deletes targeting `user/accounts/` may
        // only act on image files (avatars). Without this gate, a holder of
        // `api.media.write` could `unlink` arbitrary account YAMLs.
        if ($classification === 'accounts') {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ACCOUNTS_IMAGE_EXTENSIONS, true)) {
                throw new ForbiddenException(
                    "Deletes under user/accounts/ are restricted to avatar image files."
                );
            }
            // Upload only lets a caller into another account's folder with
            // api.users.write (the users/<name> scope); delete has no scope, so
            // without that permission it may only remove the caller's own avatar.
            if (!$this->mayWriteUsersScope($request) && !$this->isOwnAvatar($request, $filename)) {
                throw new ForbiddenException(
                    "Deleting another account's files requires the 'api.users.write' permission."
                );
            }
        }
        $lifted = $this->carriesContentExtension($filename)
            ? $this->liftedExtensions(
                (string) ($body['field'] ?? ''),
                (string) ($body['scope'] ?? ''),
                null,
                $path
            )
            : [];
        $this->assertSafeExtension($filename, false, $lifted);

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
     * Whether $filename is one of the files the caller's own avatar field points at.
     */
    private function isOwnAvatar(ServerRequestInterface $request, string $filename): bool
    {
        foreach ((array) $this->getUser($request)->get('avatar', []) as $key => $entry) {
            $candidates = [(string) $key];
            if (is_array($entry)) {
                $candidates[] = (string) ($entry['name'] ?? '');
                $candidates[] = (string) ($entry['path'] ?? '');
            }
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && basename($candidate) === $filename) {
                    return true;
                }
            }
        }

        return false;
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

    private function processUploadedFile(
        UploadedFileInterface $file,
        string $targetDir,
        bool $isAccountsDir,
        ?UploadFieldSettings $settings = null,
        array $lifted = [],
    ): string {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('File upload failed.');
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_SIZE) {
            throw new ValidationException(
                sprintf('File exceeds maximum allowed size of %d MB.', self::MAX_UPLOAD_SIZE / 1_048_576)
            );
        }
        $settings?->assertFilesize($size);

        $originalName = $file->getClientFilename() ?? 'upload';
        $filename = basename($originalName);

        $this->assertSafeFilename($filename);
        // Extension policy first (the security floor), then the field's accept
        // allowlist. Both run against the original name; random_name/
        // avoid_overwriting are applied afterwards and preserve the extension.
        $this->assertSafeExtension($filename, $isAccountsDir, $lifted);
        $settings?->assertAccepted($filename);

        if ($settings !== null) {
            $filename = $settings->resolveFilename($filename, $targetDir);
        }

        $targetPath = $targetDir . '/' . $filename;
        $file->moveTo($targetPath);

        // SVG can carry stored-XSS payloads that execute when served inline as
        // image/svg+xml (GHSA-7vhm-8x52-2r5p) — sanitize in place like core's
        // upload paths. Relevant here for avatar SVGs under user/accounts/.
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
            Security::sanitizeSVG($targetPath);
        }

        return $filename;
    }

    /**
     * Reject filenames that would escape the target dir or hide as a dotfile,
     * that carry control or invisible format characters, or that end in
     * whitespace or a dot (which some filesystems and servers strip, so the
     * stored name would not be the one checked).
     */
    private function assertSafeFilename(string $filename): void
    {
        if (
            $filename === ''
            || str_contains($filename, '..')
            || str_contains($filename, "\0")
            || str_starts_with($filename, '.')
            || preg_match('/[\p{Cc}\p{Cf}]/u', $filename) !== 0
            || preg_match('/[\s\p{Z}.]$/u', $filename) !== 0
        ) {
            throw new ValidationException("Invalid filename: '{$filename}'.");
        }
    }

    /**
     * Apply layered extension policy:
     *
     *   1. `security.uploads_dangerous_extensions` (Grav-wide denylist: php, js, exe, ...)
     *   2. Per-endpoint denylist for known-config formats (yaml, json, twig, ...)
     *   3. Page content and stylesheets (md, css, ...), unless `$lifted` names
     *      them: the extensions the owning field's blueprint allows
     *   4. If target is `user/accounts/`, restrict to image extensions only —
     *      the directory doubles as Grav's authoritative account store, so
     *      anything non-image is a privesc surface (GHSA-6xx2-m8wv-756h).
     *
     * Returns the lowercased extension for callers that want it.
     *
     * @param string[] $lifted From liftedExtensions(); only ever CONTENT_EXTENSIONS entries.
     */
    private function assertSafeExtension(string $filename, bool $isAccountsDir, array $lifted = []): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ValidationException('Uploaded file must have a file extension.');
        }

        $dangerous = array_map('strtolower', (array) $this->config->get('security.uploads_dangerous_extensions', []));

        // Inspect EVERY dot-separated component, not just the last. "shell.php.jpg"
        // has a harmless final ".jpg" but the web server may still execute the
        // earlier ".php" — the double-extension bypass (GHSA-66v2-vxxf-xc3v).
        // Each part is also checked URL-decoded and trimmed, so `twig%20` or
        // `php%00` can't slip past as a name the denylists don't recognize.
        $parts = self::extensionParts($filename);
        foreach ($parts as $part) {
            if (in_array($part, $dangerous, true)) {
                throw new ValidationException("File extension '.{$part}' is not allowed for security reasons.");
            }
            if (in_array($part, self::CONFIG_EXTENSIONS, true)) {
                throw new ValidationException("File extension '.{$part}' is not allowed for blueprint uploads.");
            }
            if (in_array($part, self::CONTENT_EXTENSIONS, true) && !in_array($part, $lifted, true)) {
                throw new ValidationException(
                    "File extension '.{$part}' is not allowed for blueprint uploads unless the field's blueprint lists it in 'allow_extensions'."
                );
            }
        }

        if ($isAccountsDir && !in_array($extension, self::ACCOUNTS_IMAGE_EXTENSIONS, true)) {
            throw new ValidationException(
                "Only image files (" . implode(', ', self::ACCOUNTS_IMAGE_EXTENSIONS) . ") may be uploaded to user/accounts/."
            );
        }

        return $extension;
    }

    /**
     * The dot-separated parts of $filename after the first, lowercased, each
     * alongside its URL-decoded, trimmed form when that differs.
     *
     * @return string[]
     */
    private static function extensionParts(string $filename): array
    {
        $parts = [];
        foreach (array_slice(explode('.', strtolower($filename)), 1) as $part) {
            $parts[] = $part;
            $decoded = strtolower(trim(rawurldecode($part)));
            if ($decoded !== $part) {
                $parts[] = $decoded;
            }
        }

        return $parts;
    }

    /**
     * Whether any dot-separated part of $filename is a content/style extension,
     * i.e. whether the field's `allow_extensions` could matter at all.
     */
    private function carriesContentExtension(string $filename): bool
    {
        return array_intersect(self::extensionParts($filename), self::CONTENT_EXTENSIONS) !== [];
    }

    /**
     * The content/style extensions a blueprint field lets this request through.
     *
     * The opt-in is read from the blueprint that owns `$scope`, looked up on the
     * server; the request only names the field. It applies when that field
     * exists, is `type: file`, declares `allow_extensions`, and the request
     * writes where the field writes: on upload the request's destination must be
     * the field's own `destination`; on delete the file must sit in the folder
     * that destination resolves to. Anything else (no field, no scope, a scope
     * that owns no blueprint, an unknown field) lifts nothing.
     *
     * Only CONTENT_EXTENSIONS entries survive, so `allow_extensions: [php]` or
     * `[yaml]` lifts nothing. Dangerous extensions, config formats, the
     * accounts image-only rule, the config/env directory block and traversal
     * guards are all enforced regardless.
     *
     * @return string[]
     */
    private function liftedExtensions(string $fieldName, string $scope, ?string $destination, ?string $deletePath): array
    {
        if ($fieldName === '' || $scope === '') {
            return [];
        }

        try {
            $fields = $this->scopeBlueprintFields($scope);
        } catch (\Throwable) {
            return [];
        }
        if ($fields === null) {
            return [];
        }

        $field = BlueprintLoader::findField($fields, $fieldName);
        if ($field === null || ($field['type'] ?? null) !== 'file') {
            return [];
        }

        $fieldDestination = rtrim((string) ($field['destination'] ?? ''), '/');
        if ($fieldDestination === '') {
            return [];
        }

        if ($destination !== null && rtrim($destination, '/') !== $fieldDestination) {
            return [];
        }

        if ($deletePath !== null) {
            $parent = $this->resolver()->logicalParent($fieldDestination, $scope);
            if ($parent === null || $this->logicalDir($deletePath) !== trim($parent, '/')) {
                return [];
            }
        }

        $allowed = $field['allow_extensions'] ?? [];
        if (is_string($allowed)) {
            $allowed = explode(',', $allowed);
        }
        if (!is_array($allowed)) {
            return [];
        }

        $lifted = [];
        foreach ($allowed as $extension) {
            $extension = ltrim(strtolower(trim((string) $extension)), '.');
            if (in_array($extension, self::CONTENT_EXTENSIONS, true)) {
                $lifted[] = $extension;
            }
        }

        return array_values(array_unique($lifted));
    }

    /**
     * Field tree of the blueprint that owns `$scope`, or null when the scope
     * owns none. A seam so tests can supply a blueprint without booting Grav.
     *
     * @return array<string, mixed>|null
     */
    protected function scopeBlueprintFields(string $scope): ?array
    {
        return (new BlueprintLoader($this->grav))->fieldsForScope($scope);
    }

    /**
     * User-rooted folder of a delete `path`, in the same form logicalParent()
     * returns (no `user/` prefix, no surrounding slashes).
     */
    private function logicalDir(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'user/')) {
            $path = substr($path, 5);
        }
        $dir = dirname($path);

        return $dir === '.' ? '' : trim($dir, '/');
    }

    /**
     * Hard-deny writes and deletes in directories that must not take arbitrary
     * files, and return the directory's classification for the caller's
     * follow-up checks. Super admin below always means super with the API-key
     * scope cap applied, and so does the config permission.
     *
     *   - `user/config/` and any `user/env/.../config/`: Grav reads them as
     *     authoritative configuration. Refused for everyone.
     *   - Twig template folders (a theme's or plugin's `templates/`, or any
     *     registered Twig path): every file there is site template code,
     *     whatever its extension. Super admin only.
     *   - Anywhere else in `user/themes/` or `user/plugins/`: needs
     *     `api.config.write`. A theme or plugin settings file field (a logo, a
     *     favicon) is part of editing that theme's or plugin's settings, and a
     *     theme or plugin may hand any of its folders to Twig.
     *   - Anywhere outside `user/` (cache, logs, system, ...): super admin only.
     *   - `user/accounts/` is allowed (avatars) but extension-restricted in
     *     `assertSafeExtension()` and the delete path.
     *
     * `$filename` is optional — pass it for delete-path checks (where we
     * have the final filename) so the error message can name the target;
     * for upload checks the per-file extension policy fires later anyway.
     */
    private function guardTargetDir(ServerRequestInterface $request, string $absoluteDir, ?string $filename = null): ?string
    {
        $classification = $this->resolver()->classifyTargetDir($absoluteDir);
        $where = $filename !== null ? "'{$filename}' under" : 'into';
        $action = $filename !== null ? 'Deleting' : 'Uploading';

        if ($classification === 'config' || $classification === 'env') {
            throw new ForbiddenException(
                "Uploads {$where} the '{$classification}' directory are not allowed via this endpoint."
            );
        }

        if ($classification === 'templates' && !$this->isSuperWithinScope($request)) {
            throw new ForbiddenException(
                "{$action} files in a theme or plugin templates folder requires super admin access."
            );
        }

        if ($classification === 'outside' && !$this->isSuperWithinScope($request)) {
            throw new ForbiddenException(
                "{$action} files outside the user folder requires super admin access."
            );
        }

        if (
            $classification === 'theme_or_plugin'
            && !$this->isSuperWithinScope($request)
            && !$this->hasPermissionWithinScope($request, 'api.config.write')
        ) {
            throw new ForbiddenException(
                "{$action} files in a theme or plugin folder requires the 'api.config.write' permission."
            );
        }

        return $classification;
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
