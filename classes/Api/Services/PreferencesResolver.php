<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use RocketTheme\Toolbox\File\YamlFile;

/**
 * Resolves admin-next UI preferences across three storage tiers:
 *
 *   Tier A — Site branding (logo + text), stored in user/config/admin-next.yaml
 *            under `ui.branding`. No per-user override (uniform brand).
 *
 *   Tier B — Site default + per-user override (theme, accent, fonts, editor
 *            mode, auto-save, collab, language, page-list size). Defaults in
 *            `ui.defaults`; user overrides under `admin_next.preferences` in
 *            the account YAML. A user override of `null` removes that key.
 *
 *   Tier C — Per-user synced (currently `menubarLinks`). No site default;
 *            same per-user storage as Tier B.
 *
 * Device-local UI state (sidebar collapse, page list view mode, etc.) is NOT
 * managed here; the SPA keeps that in localStorage.
 */
class PreferencesResolver
{
    public const SITE_CONFIG_FILE = 'admin-next.yaml';

    private const VALID_COLOR_MODE = ['', 'light', 'dark'];
    private const VALID_FONT_FAMILY = ['inter', 'google-sans', 'public-sans', 'nunito-sans', 'jost'];
    private const VALID_FONT_SIZE = ['small', 'normal', 'large', 'xlarge'];
    private const VALID_EDITOR_MODE = ['normal', 'expert'];
    private const VALID_LOGO_MODE = ['default', 'text', 'custom'];
    private const VALID_PAGES_VIEW_MODE = ['tree', 'list', 'miller'];
    private const VALID_ACCOUNTS_VIEW_MODE = ['cards', 'table'];

    public function __construct(
        private readonly Grav $grav,
    ) {}

    /**
     * Tier B built-in baseline — keys that can be overridden per-user. Used
     * when neither site nor user has set a value.
     *
     * @return array<string, mixed>
     */
    public function defaultPreferences(): array
    {
        return [
            'colorMode' => '',
            'accentHue' => 271,
            'accentSaturation' => 91,
            'fontFamily' => 'google-sans',
            'fontSize' => 'normal',
            'editorMode' => 'normal',
            'editorStickyToolbar' => true,
            'editorFixedHeight' => 0,
            'adminLanguage' => 'en',
            'pagesPerPage' => 20,
            'pagesViewMode' => 'tree',
            'usersViewMode' => 'cards',
            'groupsViewMode' => 'cards',
            'pluginsViewMode' => 'cards',
            'themesViewMode' => 'cards',
        ];
    }

    /**
     * Tier A2 built-in baseline — site-only behavioral settings that are
     * not user-overridable (auto-save, real-time collab, menubar links).
     * The admin sets these once for everyone.
     *
     * @return array<string, mixed>
     */
    public function defaultSiteSettings(): array
    {
        return [
            'autoSaveEnabled' => false,
            'autoSaveToolbarUndo' => true,
            'autoSaveBatchWindowMs' => 0,
            'collabEnabled' => true,
            'menubarLinks' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultBranding(): array
    {
        return [
            'mode' => 'default',
            'text' => 'Grav',
            'logoLight' => '',
            'logoDark' => '',
            // Custom labelling shown pre-auth on the sign-in screen and in the
            // browser tab. Empty = fall back to the built-in "Grav Admin" copy.
            'title' => '',
            'subtitle' => '',
            // Hide the "Powered by Grav CMS" line on the login/setup screens.
            // Same anti-fingerprinting rationale as withholding versions pre-auth.
            'showPoweredBy' => true,
            // Custom favicon (basename only, stored alongside the logos). Empty =
            // the SPA's generated accent-coloured favicon.
            'favicon' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function siteBranding(): array
    {
        $ui = $this->readSiteUiBlock();
        $raw = is_array($ui['branding'] ?? null) ? $ui['branding'] : [];
        return $this->normalizeBranding($raw, $this->defaultBranding());
    }

    /**
     * @return array<string, mixed>
     */
    public function sitePreferences(): array
    {
        $ui = $this->readSiteUiBlock();
        $raw = is_array($ui['defaults'] ?? null) ? $ui['defaults'] : [];
        return $this->normalizePreferences($raw, $this->defaultPreferences(), strict: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function siteSettings(): array
    {
        $ui = $this->readSiteUiBlock();
        $raw = is_array($ui['settings'] ?? null) ? $ui['settings'] : [];
        return $this->normalizeSiteSettings($raw, $this->defaultSiteSettings(), strict: false);
    }

    /**
     * Read the user's saved overrides from their account YAML.
     *
     * Stored under `admin_next.preferences`. Sits next to `admin_next.dashboard`
     * which is owned by DashboardLayoutResolver — the two are independent.
     *
     * @return array<string, mixed>
     */
    public function userPreferences(UserInterface $user): array
    {
        $adminNext = $user->get('admin_next');
        if (!is_array($adminNext)) {
            return [];
        }
        $prefs = $adminNext['preferences'] ?? [];
        return is_array($prefs) ? $prefs : [];
    }

    /**
     * Return the full resolved preferences payload for the SPA.
     *
     * @return array{
     *   branding: array<string, mixed>,
     *   site: array<string, mixed>,
     *   user: array<string, mixed>,
     *   effective: array<string, mixed>,
     *   can_edit_site: bool
     * }
     */
    public function resolve(UserInterface $user, bool $canEditSite): array
    {
        $defaults = $this->defaultPreferences();
        $site = $this->sitePreferences();
        $userPrefs = $this->userPreferences($user);
        $siteSettings = $this->siteSettings();

        // Tier B resolution: built-in defaults ⊕ site defaults ⊕ user overrides.
        $effective = array_replace($defaults, $site);
        foreach ($userPrefs as $key => $value) {
            if ($value === null || !array_key_exists($key, $defaults)) {
                continue;
            }
            $effective[$key] = $value;
        }
        // Tier A2 site-only behavioral settings are applied last and are not
        // user-overridable. Merging them into `effective` lets the SPA read
        // every applicable value from one map.
        $effective = array_replace($effective, $siteSettings);

        return [
            'branding' => $this->siteBranding(),
            'site' => $site,
            'site_settings' => $siteSettings,
            'user' => $userPrefs,
            'effective' => $effective,
            'can_edit_site' => $canEditSite,
        ];
    }

    /**
     * Persist site-wide defaults. Replaces the entire `ui.defaults` block.
     *
     * @param array<string, mixed> $payload
     */
    public function saveSitePreferences(array $payload): void
    {
        $normalized = $this->normalizePreferences($payload, $this->defaultPreferences(), strict: true);
        $this->writeSiteUiKey('defaults', $normalized);
    }

    /**
     * Persist site branding. Replaces the entire `ui.branding` block.
     *
     * @param array<string, mixed> $payload
     */
    public function saveSiteBranding(array $payload): void
    {
        $normalized = $this->normalizeBranding($payload, $this->defaultBranding());
        $this->writeSiteUiKey('branding', $normalized);
    }

    /**
     * Persist site-only Tier A2 settings (auto-save, collab, menubar links).
     * Patch semantics: only keys present in the payload are written; others
     * are read from the existing yaml so callers can update a subset.
     *
     * @param array<string, mixed> $payload
     */
    public function saveSiteSettings(array $payload): void
    {
        $merged = array_replace($this->siteSettings(), $payload);
        $normalized = $this->normalizeSiteSettings($merged, $this->defaultSiteSettings(), strict: true);
        $this->writeSiteUiKey('settings', $normalized);
    }

    /**
     * Patch the current user's overrides.
     *
     * Semantics: keys with `null` values are removed from the override map
     * (i.e. "reset to site default"). Keys not present in the payload are
     * left alone. Pass an explicit empty array to clear an override list
     * (e.g. `menubarLinks: []`).
     *
     * @param array<string, mixed> $payload
     */
    public function saveUserPreferences(UserInterface $user, array $payload): void
    {
        $current = $this->userPreferences($user);
        $whitelist = $this->userKeyWhitelist();

        foreach ($payload as $key => $value) {
            if (!in_array($key, $whitelist, true)) {
                continue;
            }
            if ($value === null) {
                unset($current[$key]);
                continue;
            }
            $coerced = $this->coerceValue($key, $value);
            if ($coerced === null) {
                // Invalid input — silently drop rather than corrupt the file.
                continue;
            }
            $current[$key] = $coerced;
        }

        $adminNext = $user->get('admin_next');
        $adminNext = is_array($adminNext) ? $adminNext : [];
        if ($current === []) {
            unset($adminNext['preferences']);
        } else {
            $adminNext['preferences'] = $current;
        }
        $user->set('admin_next', $adminNext);
        $user->save();
    }

    /**
     * Clear ALL user overrides — used by "Reset to site defaults" in the UI.
     */
    public function clearUserPreferences(UserInterface $user): void
    {
        $adminNext = $user->get('admin_next');
        if (!is_array($adminNext)) {
            return;
        }
        unset($adminNext['preferences']);
        $user->set('admin_next', $adminNext);
        $user->save();
    }

    /**
     * Resolve `user://media/admin-next/` and ensure it exists if requested.
     */
    public function brandingMediaDir(bool $createDir = false): ?string
    {
        $locator = $this->grav['locator'] ?? null;
        if ($locator === null) {
            return null;
        }
        $base = $locator->findResource('user://', true);
        if (!$base) {
            return null;
        }
        $dir = $base . '/media/admin-next';
        if (!is_dir($dir)) {
            if (!$createDir) {
                return $dir;
            }
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
        }
        return $dir;
    }

    /**
     * Public URL fragment a logo path resolves to, relative to the site root.
     * Returns empty string for empty/missing paths so the SPA can treat that
     * as "fall back to built-in logo".
     */
    public function brandingMediaUrl(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return '';
        }
        // Strip any leading slashes / path traversal; we only store basenames.
        $filename = basename($filename);
        return '/user/media/admin-next/' . $filename;
    }

    /**
     * Whitelist of keys the user may override (Tier B only — Tier A2 are
     * site-only and rejected here).
     *
     * @return array<int, string>
     */
    private function userKeyWhitelist(): array
    {
        return array_keys($this->defaultPreferences());
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function normalizePreferences(array $input, array $defaults, bool $strict): array
    {
        $out = $strict ? [] : $defaults;
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $coerced = $this->coerceValue($key, $input[$key]);
            if ($coerced === null) {
                // Bad value — fall back to default in non-strict mode, drop in strict.
                if (!$strict) {
                    $out[$key] = $defaultValue;
                }
                continue;
            }
            $out[$key] = $coerced;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function normalizeSiteSettings(array $input, array $defaults, bool $strict): array
    {
        $out = $strict ? [] : $defaults;
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if ($key === 'menubarLinks') {
                $out[$key] = $this->normalizeMenubarLinks($input[$key]);
                continue;
            }
            $coerced = $this->coerceValue($key, $input[$key]);
            if ($coerced === null) {
                if (!$strict) {
                    $out[$key] = $defaultValue;
                }
                continue;
            }
            $out[$key] = $coerced;
        }
        return $out;
    }

    /**
     * Coerce a single Tier-B key to its valid type, or return null if the
     * value cannot be coerced. `null` from this method always means "reject".
     */
    private function coerceValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'colorMode' => is_string($value) && in_array($value, self::VALID_COLOR_MODE, true) ? $value : null,
            'accentHue' => is_numeric($value) ? max(0, min(360, (int) $value)) : null,
            'accentSaturation' => is_numeric($value) ? max(0, min(100, (int) $value)) : null,
            'fontFamily' => is_string($value) && in_array($value, self::VALID_FONT_FAMILY, true) ? $value : null,
            'fontSize' => is_string($value) && in_array($value, self::VALID_FONT_SIZE, true) ? $value : null,
            'editorMode' => is_string($value) && in_array($value, self::VALID_EDITOR_MODE, true) ? $value : null,
            'editorStickyToolbar', 'autoSaveEnabled', 'autoSaveToolbarUndo', 'collabEnabled' => is_bool($value) ? $value : (is_scalar($value) ? (bool) $value : null),
            // 0 = auto-grow (disabled); any other value is clamped to a sane fixed-height range.
            'editorFixedHeight' => is_numeric($value) ? (((int) $value) <= 0 ? 0 : max(300, min(1200, (int) $value))) : null,
            'autoSaveBatchWindowMs' => is_numeric($value) ? max(0, (int) $value) : null,
            'adminLanguage' => is_string($value) && $value !== '' ? substr($value, 0, 32) : null,
            'pagesPerPage' => is_numeric($value) ? max(1, min(200, (int) $value)) : null,
            'pagesViewMode' => is_string($value) && in_array($value, self::VALID_PAGES_VIEW_MODE, true) ? $value : null,
            'usersViewMode', 'groupsViewMode', 'pluginsViewMode', 'themesViewMode' => is_string($value) && in_array($value, self::VALID_ACCOUNTS_VIEW_MODE, true) ? $value : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function normalizeBranding(array $input, array $defaults): array
    {
        $mode = $input['mode'] ?? $defaults['mode'];
        if (!is_string($mode) || !in_array($mode, self::VALID_LOGO_MODE, true)) {
            $mode = $defaults['mode'];
        }
        $text = $input['text'] ?? $defaults['text'];
        if (!is_string($text)) {
            $text = $defaults['text'];
        }
        $text = trim($text);
        if ($text === '') {
            $text = $defaults['text'];
        }

        // Free-text title/subtitle: optional, so an empty string is a valid
        // "use the default copy" signal rather than being coerced back.
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : $defaults['title'];
        $subtitle = is_string($input['subtitle'] ?? null) ? trim($input['subtitle']) : $defaults['subtitle'];

        $showPoweredBy = $input['showPoweredBy'] ?? $defaults['showPoweredBy'];
        if (!is_bool($showPoweredBy)) {
            $showPoweredBy = is_scalar($showPoweredBy) ? (bool) $showPoweredBy : $defaults['showPoweredBy'];
        }

        return [
            'mode' => $mode,
            'text' => substr($text, 0, 64),
            'logoLight' => $this->sanitizeLogoPath($input['logoLight'] ?? ''),
            'logoDark' => $this->sanitizeLogoPath($input['logoDark'] ?? ''),
            'title' => substr($title, 0, 64),
            'subtitle' => substr($subtitle, 0, 128),
            'showPoweredBy' => $showPoweredBy,
            'favicon' => $this->sanitizeLogoPath($input['favicon'] ?? ''),
        ];
    }

    private function sanitizeLogoPath(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        // Store only the basename; resolver controls the directory.
        $name = basename(trim($value));
        if (str_contains($name, '..') || str_contains($name, "\0") || str_starts_with($name, '.')) {
            return '';
        }
        return $name;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMenubarLinks(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';
            $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : '';
            if ($label === '' || $url === '') {
                continue;
            }
            $link = ['label' => substr($label, 0, 64), 'url' => substr($url, 0, 512)];
            if (isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== '') {
                $link['icon'] = substr($entry['icon'], 0, 64);
            }
            if (isset($entry['external'])) {
                $link['external'] = (bool) $entry['external'];
            }
            $out[] = $link;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readSiteUiBlock(): array
    {
        $path = $this->siteConfigFilePath();
        if (!$path || !is_file($path)) {
            return [];
        }
        $content = (array) YamlFile::instance($path)->content();
        return is_array($content['ui'] ?? null) ? $content['ui'] : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function writeSiteUiKey(string $key, array $value): void
    {
        $path = $this->siteConfigFilePath(true);
        if (!$path) {
            throw new \RuntimeException('Unable to resolve user/config path for admin-next.yaml.');
        }
        $file = YamlFile::instance($path);
        $content = (array) $file->content();
        $content['ui'] = is_array($content['ui'] ?? null) ? $content['ui'] : [];
        $content['ui'][$key] = $value;
        $file->content($content);
        $file->save();

        $config = $this->grav['config'] ?? null;
        if ($config) {
            $config->set('admin-next.ui.' . $key, $value);
        }
    }

    /**
     * Mirror of DashboardLayoutResolver::siteConfigFilePath() so the two
     * resolvers stay loosely coupled. Resolves to user/config/admin-next.yaml.
     */
    private function siteConfigFilePath(bool $createDir = false): ?string
    {
        $locator = $this->grav['locator'] ?? null;
        if ($locator === null) {
            return null;
        }
        $userConfigDir = $locator->findResource('user://config', true) ?: null;
        if ($userConfigDir === null) {
            $userPath = $locator->findResource('user://', true);
            if ($userPath && $createDir) {
                $userConfigDir = $userPath . '/config';
                if (!is_dir($userConfigDir)) {
                    mkdir($userConfigDir, 0775, true);
                }
            }
        }
        if (!$userConfigDir) {
            return null;
        }
        return $userConfigDir . '/' . self::SITE_CONFIG_FILE;
    }
}
