<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Services\DisabledPluginLangIndex;
use Grav\Plugin\Api\Services\PreferencesResolver;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Resolves admin-facing label strings against the authenticated user's chosen
 * admin language and translates language-key-looking values (e.g.
 * `PLUGIN_FOO.BAR`) the way Admin Next expects.
 *
 * Used by any controller that serializes blueprint/config labels for the SPA.
 * The host controller must extend {@see AbstractApiController} (the trait
 * relies on its `$grav`, `$config` and `getUser()` members).
 *
 * Prime the per-request language chain once per payload with
 * {@see primeAdminLanguages()} before calling {@see translateLabel()}; without
 * it, labels fall back to Grav's normal lookup behaviour.
 */
trait TranslatesAdminLabels
{
    private ?DisabledPluginLangIndex $disabledLangIndex = null;

    /**
     * Language fallback chain used when translating labels for the current
     * request — typically [$userAdminLanguage, 'en']. Resolved lazily via
     * {@see primeAdminLanguages()} and cached on the instance, so the
     * per-request preference lookup runs at most once per payload regardless of
     * how many labels need translating.
     *
     * @var array<int, string>|null
     */
    private ?array $adminLabelLanguages = null;

    /**
     * Map of primary subtag => shipped region-suffixed locale codes, e.g.
     * `['en' => ['en-US'], 'de' => ['de-DE']]`. Cached per request.
     *
     * @var array<string, array<int, string>>|null
     */
    private ?array $regionVariantIndex = null;

    private function disabledLangIndex(): DisabledPluginLangIndex
    {
        return $this->disabledLangIndex ??= new DisabledPluginLangIndex($this->grav);
    }

    /**
     * Resolve and cache the language chain for label translation. Prefers the
     * authenticated user's `adminLanguage` preference (which the SPA picks),
     * with 'en' as a fallback so any keys not yet translated still come
     * through in English instead of being humanized.
     *
     * Why this is needed: Grav's `Language::translate()` falls back to the
     * site's active content language when called with no `$languages` hint —
     * that's typically 'en' even for an admin user who has selected Hebrew
     * for their UI. The dict endpoint (`/translations/{lang}`) already
     * accepts an explicit language, so admin-next's client-side i18n works,
     * but labels are pre-resolved server-side here.
     *
     * @return array<int, string>
     */
    protected function primeAdminLanguages(ServerRequestInterface $request): array
    {
        if ($this->adminLabelLanguages !== null) {
            return $this->adminLabelLanguages;
        }

        $lang = 'en';
        try {
            $user = $this->getUser($request);
            $resolver = new PreferencesResolver($this->grav, $this->config);
            $effective = $resolver->resolve($user, false)['effective'] ?? [];
            $candidate = $effective['adminLanguage'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $lang = $candidate;
            }
        } catch (Throwable) {
            // Unauthenticated or resolver failure — fall back to English.
        }

        return $this->adminLabelLanguages = $this->expandLanguageChain($lang);
    }

    /**
     * Build the translation fallback chain for a requested admin language.
     *
     * The requested language comes first and English is the universal tail
     * fallback. Each entry is then expanded to include any region-suffixed
     * variant that ships on disk: admin2 stores its dictionary under e.g.
     * `en-US.yaml` (not `en.yaml`), and Grav indexes plugin language files by
     * the filename's locale code. Without this expansion a user whose
     * preference is the bare 2-char `en` never reaches admin2's `en-US` strings
     * and every blueprint label/help falls through to the humaniser
     * (getgrav/grav-admin-next#1). Expanding `en` → `['en', 'en-US']` (and
     * likewise `de` → `['de', 'de-DE']`) lets the shipped region file serve the
     * bare code, so no duplicate `en.yaml` is needed.
     *
     * @return array<int, string>
     */
    private function expandLanguageChain(string $lang): array
    {
        $chain = [];
        foreach ([$lang, 'en'] as $code) {
            foreach (array_merge([$code], $this->regionVariantsFor($code)) as $candidate) {
                if (!in_array($candidate, $chain, true)) {
                    $chain[] = $candidate;
                }
            }
        }

        return $chain;
    }

    /**
     * Region-suffixed locale codes shipped for a bare primary subtag, e.g.
     * `en` => `['en-US']`. Already-regioned codes (containing `-`) need no
     * expansion and return an empty list.
     *
     * @return array<int, string>
     */
    private function regionVariantsFor(string $code): array
    {
        if (str_contains($code, '-')) {
            return [];
        }

        return $this->buildRegionVariantIndex()[$code] ?? [];
    }

    /**
     * Discover shipped region variants from admin2's languages directory (where
     * the SPA's translation dictionary lives). Cached for the request.
     *
     * @return array<string, array<int, string>>
     */
    private function buildRegionVariantIndex(): array
    {
        if ($this->regionVariantIndex !== null) {
            return $this->regionVariantIndex;
        }

        $index = [];
        $dir = $this->grav['locator']->findResource('plugin://admin2/languages')
            ?: (defined('GRAV_ROOT') ? GRAV_ROOT . '/user/plugins/admin2/languages' : null);

        if (is_string($dir) && is_dir($dir)) {
            foreach (glob($dir . '/*.yaml') ?: [] as $file) {
                $localeCode = basename($file, '.yaml');
                $dash = strpos($localeCode, '-');
                if ($dash !== false) {
                    $index[substr($localeCode, 0, $dash)][] = $localeCode;
                }
            }
        }

        return $this->regionVariantIndex = $index;
    }

    /**
     * Translate a blueprint / permission / config label string.
     *
     * Lookup order, ICU-first:
     *   1. `ICU.<key>` — admin2's authoritative namespace (Grav 2 convention).
     *   2. `<key>` — flat lookup, for legacy plugins that ship PLUGIN_ADMIN.*
     *      under the Grav 1 convention (form, login, flex-objects, etc.).
     *   3. `PLUGIN_API.<last-segment>` — last-resort api-plugin namespace.
     *   4. Humanizer over the key itself.
     *
     * ICU is checked first by design: admin classic's plugin folder may still
     * be present in dev installs (disabled, mid-migration) and Grav core's
     * `flattenByLang()` reads every plugin's lang files regardless of enabled
     * state. Without the ICU-first order, admin classic's flat values would
     * shadow admin2's ICU ports — a per-key drift that's hard to spot. Putting
     * ICU first makes admin2 the source of truth for any key it ships, and
     * lets the flat lookup serve as a transition fallback for keys admin2
     * hasn't ported (or that legitimate 3rd-party plugins ship under
     * PLUGIN_ADMIN.* for shared-vocabulary labels).
     */
    protected function translateLabel(string $label): string
    {
        $lang = $this->grav['language'];
        // Use the per-request language chain (set by primeAdminLanguages())
        // so labels resolve against the user's chosen admin language, not the
        // site's default content language. Falls back to no override when no
        // endpoint primed the chain — that preserves Grav's normal lookup
        // behaviour for any caller (e.g. test code) that calls translateLabel()
        // directly.
        $languages = $this->adminLabelLanguages;
        $primary = $languages[0] ?? ($lang->getLanguage() ?: 'en');

        // If it looks like a language key (e.g. PLUGIN_ADMIN.ACCESS_SITE), try to translate
        if (str_contains($label, '.') && strtoupper($label) === $label) {
            $icuKey = 'ICU.' . $label;
            $icuTranslated = $lang->translate($icuKey, $languages);
            if ($icuTranslated !== $icuKey) {
                return $icuTranslated;
            }

            // admin2 consolidated its shared PLUGIN_ADMIN vocabulary into the
            // ICU.ADMIN_NEXT namespace so the translation service — scoped to
            // ADMIN_NEXT — actually translates it into every locale. Blueprints
            // (and 160+ plugins) still reference the public PLUGIN_ADMIN.* keys,
            // so alias them onto ICU.ADMIN_NEXT.* here. A handful of nav-word
            // keys (GROUPS/MEDIA/PAGES/SETTINGS/SYSTEM) resolve to a nested map
            // under ADMIN_NEXT rather than a string; the is_string guard lets
            // those fall through to the humaniser (which yields the right word).
            if (str_starts_with($label, 'PLUGIN_ADMIN.')) {
                $aliasKey = 'ICU.ADMIN_NEXT.' . substr($label, strlen('PLUGIN_ADMIN.'));
                // array_support=true returns the raw node instead of casting an
                // array to string, so a key that lands on a nested namespace
                // (GROUPS/MEDIA/PAGES/SETTINGS/SYSTEM) comes back as an array and
                // is skipped here rather than blowing up on "Array to string".
                $aliasTranslated = $lang->translate($aliasKey, $languages, true);
                if (is_string($aliasTranslated) && $aliasTranslated !== $aliasKey) {
                    return $aliasTranslated;
                }
            }

            // Skip the flat lookup if the only source for this key is a disabled
            // plugin — a disabled plugin shouldn't influence what admin2 renders.
            if (!$this->disabledLangIndex()->isDisabledOnly($label, $primary)) {
                $translated = $lang->translate($label, $languages);
                if ($translated !== $label) {
                    return $translated;
                }
            }

            // Try API plugin namespace as fallback
            $key = substr($label, strrpos($label, '.') + 1);
            $apiTranslated = $lang->translate('PLUGIN_API.' . $key, $languages);
            if ($apiTranslated !== 'PLUGIN_API.' . $key) {
                return $apiTranslated;
            }
        }

        // If the label is still a raw key, derive a human-readable name from the permission name
        if (strtoupper($label) === $label && str_contains($label, '_')) {
            // PLUGIN_ADMIN.ACCESS_ADMIN_CONFIGURATION -> Configuration
            $parts = explode('.', $label);
            $last = end($parts);
            // Remove ACCESS_ prefix
            $last = preg_replace('/^ACCESS_(?:ADMIN_|SITE_)?/', '', $last);
            return ucwords(strtolower(str_replace('_', ' ', $last)));
        }

        return $label;
    }
}
