<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\PreferencesResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin-next UI preferences endpoints.
 *
 *   GET    /admin-next/preferences            — full resolved payload
 *   PATCH  /admin-next/preferences/user       — patch current user overrides
 *   DELETE /admin-next/preferences/user       — clear all current-user overrides
 *   PATCH  /admin-next/preferences/site       — super-admin: write site defaults
 *   PATCH  /admin-next/branding               — super-admin: write logo mode + text
 *   POST   /admin-next/branding/logo          — super-admin: upload logo file
 *   DELETE /admin-next/branding/logo          — super-admin: delete a logo file
 *
 * The SPA fetches once on boot, then PATCHes deltas as the user changes
 * preferences. See PreferencesResolver for storage layout (Tier A/B/C).
 */
class PreferencesController extends AbstractApiController
{
    /** 4 MB cap — logos shouldn't be anywhere near this. */
    private const LOGO_MAX_SIZE = 4_194_304;

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $resolver = $this->getResolver();
        $payload = $resolver->resolve($user, $this->canEditSite($user));
        $payload['branding_urls'] = $this->resolveBrandingUrls($payload['branding'] ?? [], $resolver);

        return $this->respondWithEtag($payload);
    }

    public function saveUser(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $body = $this->getRequestBody($request);
        $user = $this->getUser($request);
        $resolver = $this->getResolver();
        $resolver->saveUserPreferences($user, $body);

        $payload = $resolver->resolve($user, $this->canEditSite($user));
        $payload['branding_urls'] = $this->resolveBrandingUrls($payload['branding'] ?? [], $resolver);

        return ApiResponse::create($payload);
    }

    public function resetUser(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $resolver = $this->getResolver();
        $resolver->clearUserPreferences($user);

        $payload = $resolver->resolve($user, $this->canEditSite($user));
        $payload['branding_urls'] = $this->resolveBrandingUrls($payload['branding'] ?? [], $resolver);

        return ApiResponse::create($payload);
    }

    public function saveSite(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSiteEditor($request);

        $body = $this->getRequestBody($request);
        $resolver = $this->getResolver();

        // Route a flat payload into the two yaml destinations: Tier B keys
        // go to `ui.defaults` (overridable per-user), Tier A2 keys go to
        // `ui.settings` (site-only behavioral). Anything else is ignored.
        $tierB = array_intersect_key($body, $resolver->defaultPreferences());
        $tierA2 = array_intersect_key($body, $resolver->defaultSiteSettings());

        if ($tierB !== []) {
            $resolver->saveSitePreferences($tierB);
        }
        if ($tierA2 !== []) {
            $resolver->saveSiteSettings($tierA2);
        }

        $user = $this->getUser($request);
        $payload = $resolver->resolve($user, true);
        $payload['branding_urls'] = $this->resolveBrandingUrls($payload['branding'] ?? [], $resolver);

        return ApiResponse::create($payload);
    }

    public function saveBranding(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSiteEditor($request);

        $body = $this->getRequestBody($request);
        $resolver = $this->getResolver();
        // Branding is replace-all: merge with current so callers can PATCH
        // just `mode` or just `text` without wiping the saved file paths.
        $merged = array_replace($resolver->siteBranding(), $body);
        $resolver->saveSiteBranding($merged);

        $user = $this->getUser($request);
        $payload = $resolver->resolve($user, true);
        $payload['branding_urls'] = $this->resolveBrandingUrls($payload['branding'] ?? [], $resolver);

        return ApiResponse::create($payload);
    }

    public function uploadLogo(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSiteEditor($request);

        $variant = $this->getLogoVariant($request);
        $uploaded = $request->getUploadedFiles();
        $file = $uploaded['file'] ?? $uploaded['logo'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('No logo file uploaded.');
        }
        $size = $file->getSize();
        if ($size !== null && $size > self::LOGO_MAX_SIZE) {
            throw new ValidationException(
                sprintf('Logo exceeds maximum size of %d MB.', self::LOGO_MAX_SIZE / 1_048_576)
            );
        }

        // Determine the real type from the file content, never the client-declared
        // MIME (getClientMediaType() is attacker-controlled — GHSA-xc64-vh46-vph6).
        // Raster formats are confirmed via getimagesizefromstring(); SVG can't be
        // parsed that way, so it is validated + sanitized as XML below so a logo
        // can't smuggle a stored-XSS payload when later served inline.
        $contents = (string) $file->getStream();
        $ext = match (@getimagesizefromstring($contents)[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };

        if ($ext === null) {
            // Not a supported raster image — the only other accepted format is SVG.
            $contents = $this->sanitizeSvg($contents);
            $ext = 'svg';
        }

        $resolver = $this->getResolver();
        $dir = $resolver->brandingMediaDir(createDir: true);
        if ($dir === null) {
            throw new \RuntimeException('Unable to resolve user://media/admin-next/.');
        }

        // Timestamp+rand keeps writes idempotent on filesystems with second-resolution mtime.
        $stamp = substr(md5(uniqid('logo', true)), 0, 10);
        $filename = "logo-{$variant}-{$stamp}.{$ext}";
        $filepath = $dir . '/' . $filename;
        // Write the validated/sanitized bytes ourselves rather than moveTo(): the
        // stream has already been read, and SVG content has been rewritten.
        if (file_put_contents($filepath, $contents) === false) {
            throw new \RuntimeException('Failed to write logo file.');
        }

        // Replace the path for this variant; preserve everything else.
        $branding = $resolver->siteBranding();
        $previous = $branding[$variant === 'light' ? 'logoLight' : 'logoDark'] ?? '';
        $branding[$variant === 'light' ? 'logoLight' : 'logoDark'] = $filename;
        // If a custom logo file was uploaded, auto-flip mode to `custom` unless the
        // operator has explicitly set `text` mode (text trumps both default + custom).
        if (($branding['mode'] ?? 'default') !== 'text') {
            $branding['mode'] = 'custom';
        }
        $resolver->saveSiteBranding($branding);

        // Clean up the previous file for this variant if it's different.
        if ($previous && $previous !== $filename) {
            $oldPath = $dir . '/' . basename($previous);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $user = $this->getUser($request);
        $payload = $resolver->resolve($user, true);
        $payload['branding_urls'] = $this->resolveBrandingUrls($payload['branding'] ?? [], $resolver);

        return ApiResponse::create($payload, 201);
    }

    public function deleteLogo(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSiteEditor($request);

        $variant = $this->getLogoVariant($request);
        $resolver = $this->getResolver();
        $branding = $resolver->siteBranding();
        $key = $variant === 'light' ? 'logoLight' : 'logoDark';
        $existing = $branding[$key] ?? '';
        if ($existing) {
            $dir = $resolver->brandingMediaDir();
            if ($dir && is_file($dir . '/' . basename($existing))) {
                @unlink($dir . '/' . basename($existing));
            }
        }
        $branding[$key] = '';
        // If both variants are now empty, revert to default mode so the SPA
        // falls back to the built-in Grav logo rather than rendering nothing.
        if ($branding['logoLight'] === '' && $branding['logoDark'] === '' && ($branding['mode'] ?? '') === 'custom') {
            $branding['mode'] = 'default';
        }
        $resolver->saveSiteBranding($branding);

        $user = $this->getUser($request);
        $payload = $resolver->resolve($user, true);
        $payload['branding_urls'] = $this->resolveBrandingUrls($payload['branding'] ?? [], $resolver);

        return ApiResponse::create($payload);
    }

    /**
     * Validate and sanitize an uploaded SVG logo so it can't carry stored XSS
     * when served inline. Parses the markup as XML (rejecting anything that
     * isn't a real <svg> document), then strips <script>/<foreignObject>
     * elements, on* event-handler attributes, and javascript:/data: URIs from
     * href/src attributes. Returns the cleaned markup.
     */
    private function sanitizeSvg(string $svg): string
    {
        $svg = trim($svg);
        if ($svg === '') {
            throw new ValidationException('Logo SVG is empty.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // No DTD load and LIBXML_NONET => no external entities / network (XXE-safe).
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (
            !$loaded
            || $dom->documentElement === null
            || strtolower($dom->documentElement->localName) !== 'svg'
        ) {
            throw new ValidationException('Logo must be a valid SVG image.');
        }

        $xpath = new \DOMXPath($dom);

        // Remove script-bearing elements entirely.
        $dangerous = $xpath->query('//*[local-name()="script" or local-name()="foreignObject"]');
        foreach (iterator_to_array($dangerous ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Strip event handlers and dangerous URI schemes from every attribute.
        $attrs = $xpath->query('//@*');
        foreach (iterator_to_array($attrs ?: []) as $attr) {
            $name = strtolower($attr->nodeName);
            $bare = str_replace('xlink:', '', $name);
            $value = $attr->nodeValue ?? '';
            $isUri = $bare === 'href' || $bare === 'src';
            if (
                str_starts_with($name, 'on')
                || ($isUri && preg_match('/^\s*(javascript|data)\s*:/i', $value))
            ) {
                $attr->ownerElement?->removeAttributeNode($attr);
            }
        }

        $clean = $dom->saveXML();
        if ($clean === false) {
            throw new ValidationException('Failed to sanitize SVG logo.');
        }

        return $clean;
    }

    private function getLogoVariant(ServerRequestInterface $request): string
    {
        $variant = $request->getQueryParams()['variant'] ?? null;
        if ($variant === null) {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $variant = $body['variant'] ?? null;
            }
        }
        $variant = is_string($variant) ? strtolower($variant) : '';
        if ($variant !== 'light' && $variant !== 'dark') {
            throw new ValidationException("Query parameter 'variant' must be 'light' or 'dark'.");
        }
        return $variant;
    }

    private function requireSiteEditor(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        if (!$this->canEditSite($user)) {
            throw new ForbiddenException('Only super-admins can edit site-wide admin preferences.');
        }
    }

    private function canEditSite(\Grav\Common\User\Interfaces\UserInterface $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    /**
     * Project filename-only branding paths into URL fragments the SPA can use directly.
     *
     * @param array<string, mixed> $branding
     * @return array{light: string, dark: string}
     */
    private function resolveBrandingUrls(array $branding, PreferencesResolver $resolver): array
    {
        return [
            'light' => $resolver->brandingMediaUrl((string) ($branding['logoLight'] ?? '')),
            'dark' => $resolver->brandingMediaUrl((string) ($branding['logoDark'] ?? '')),
        ];
    }

    private function getResolver(): PreferencesResolver
    {
        return new PreferencesResolver($this->grav);
    }
}
