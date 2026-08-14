<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\TranslationOverrideStore;
use Grav\Plugin\Api\Services\TranslationPlaceholderGuard;
use Grav\Plugin\Api\Services\TranslationSourceIndex;
use Grav\Plugin\Api\Services\TranslationStringsImporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Browse and override this site's translation strings.
 *
 * ## Why `/i18n` and not `/translations`
 *
 * `/translations/` is an **unauthenticated public prefix** in
 * {@see \Grav\Plugin\Api\ApiRouter}, sitting alongside `/auth/` and
 * `/thumbnails/` — the SPA fetches its dictionary before anyone has logged in.
 * Hanging an editor under that prefix would expose both reads and writes to
 * anonymous callers, so every route here lives under `/i18n` instead and is
 * permission-checked normally.
 *
 * ## Why this doesn't reuse `GET /translations/{lang}`
 *
 * That endpoint backfills missing keys from English so the admin UI never
 * renders a humanized key. The editor needs the opposite: a value that is
 * absent in `fr` must read as *absent*, or "missing in fr" cannot be
 * distinguished from "translated to the same words as English", and the whole
 * coverage model collapses. Reads here go straight to the source index.
 */
class TranslationsEditorController extends AbstractApiController
{
    private const MAX_PER_PAGE = 500;

    /** One machine-translation request is one provider call; keep it bounded. */
    private const MAX_TRANSLATE_KEYS = 200;

    /** Keys shown per language in the import preview; the counts carry the rest. */
    private const MAX_IMPORT_PREVIEW_KEYS = 50;

    private ?TranslationSourceIndex $sources = null;
    private ?TranslationOverrideStore $store = null;
    private ?TranslationStringsImporter $importer = null;

    /**
     * GET /i18n/sources — the provider tree.
     *
     * One entry per source that ships at least one language file, with the
     * namespaces it contributes for the requested language. This is what the
     * browse pane renders, and it exists because the common case is "I just
     * installed this theme and want to reword five things" — a user with no
     * search term who needs to see what is changeable.
     */
    public function sources(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        $lang = $this->queryLang($request, 'lang');
        $index = $this->sourceIndex();

        $out = [];
        foreach ($index->providers() as $id => $provider) {
            $namespaces = $index->namespacesForProvider($id, $lang);
            $count = array_sum($namespaces);
            if ($count === 0) {
                continue;
            }

            $out[] = [
                'id' => $id,
                'kind' => $provider['kind'],
                'slug' => $provider['slug'],
                'label' => $provider['label'],
                'enabled' => $provider['enabled'],
                'key_count' => $count,
                'namespaces' => array_map(
                    static fn(string $ns, int $n): array => ['name' => $ns, 'key_count' => $n],
                    array_keys($namespaces),
                    $namespaces
                ),
            ];
        }

        return ApiResponse::create(['lang' => $lang, 'providers' => $out]);
    }

    /**
     * GET /i18n/languages — every language code any source ships, plus which
     * of them this site has overrides for.
     */
    public function languages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        $overridden = $this->store()->languages();
        $out = [];
        foreach ($this->sourceIndex()->languages() as $code) {
            $out[] = [
                'code' => $code,
                'has_overrides' => in_array($code, $overridden, true),
            ];
        }

        return ApiResponse::create([
            'default' => $this->defaultLang(),
            'languages' => $out,
        ]);
    }

    /**
     * GET /i18n/coverage — per-language totals against a source language.
     *
     * `missing` is measured against the source language's key set, not the
     * union of every language, so a stale key that only an old translation
     * still carries doesn't inflate everyone else's shortfall.
     */
    public function coverage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        $index = $this->sourceIndex();
        $store = $this->store();
        $sourceLang = $this->queryLang($request, 'source_lang');
        $total = count($index->index($sourceLang));

        $out = [];
        foreach ($index->languages() as $code) {
            $entries = $index->index($code);
            $overrides = $store->overrides($code);

            $translated = 0;
            foreach (array_keys($index->index($sourceLang)) as $key) {
                if (isset($entries[$key]) || isset($overrides[$key])) {
                    $translated++;
                }
            }

            $out[] = [
                'code' => $code,
                'total' => $total,
                'translated' => $translated,
                'missing' => max(0, $total - $translated),
                'overridden' => count($overrides),
            ];
        }

        return ApiResponse::create(['source_lang' => $sourceLang, 'coverage' => $out]);
    }

    /**
     * GET /i18n/keys — the matrix.
     *
     * Query: `source_lang`, `langs` (comma-separated), `q`, `provider`,
     * `namespace`, `status`, `page`, `per_page`.
     *
     * `q` matches the key *and* the source-language value, because people know
     * the string, not the key — someone wanting to change the word "Search" in
     * their sidebar has no idea it lives at
     * `THEME_TYPHOON.SIDEBAR.SIMPLE_SEARCH.HEADLINE`.
     */
    public function keys(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        $params = $request->getQueryParams();
        $index = $this->sourceIndex();
        $store = $this->store();

        $sourceLang = $this->queryLang($request, 'source_lang');
        $targets = $this->requestedLanguages($params, $sourceLang);
        $status = (string) ($params['status'] ?? 'all');
        $provider = isset($params['provider']) ? (string) $params['provider'] : null;
        $namespace = isset($params['namespace']) ? (string) $params['namespace'] : null;
        $query = trim((string) ($params['q'] ?? ''));

        $sourceEntries = $index->index($sourceLang);
        $sourceOverrides = $store->overrides($sourceLang);

        // Overrides can name a key no source ships (a typo, or a string added by
        // a since-removed plugin). Those still need a row, or they'd be
        // invisible and unfixable from the editor.
        $candidates = array_keys($sourceEntries + $sourceOverrides);

        if ($provider !== null) {
            $ofProvider = $index->keysForProvider($provider, $sourceLang);
            $candidates = array_values(array_filter(
                $candidates,
                static fn(string $key): bool => isset($ofProvider[$key])
            ));
        }

        if ($namespace !== null) {
            $prefix = $namespace . '.';
            $candidates = array_values(array_filter(
                $candidates,
                static fn(string $key): bool => $key === $namespace || str_starts_with($key, $prefix)
            ));
        }

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $candidates = array_values(array_filter(
                $candidates,
                static function (string $key) use ($needle, $sourceEntries, $sourceOverrides): bool {
                    if (str_contains(mb_strtolower($key), $needle)) {
                        return true;
                    }
                    $value = $sourceOverrides[$key] ?? ($sourceEntries[$key]['value'] ?? '');

                    return str_contains(mb_strtolower($value), $needle);
                }
            ));
        }

        // Build the cells before paginating: a status filter is a property of
        // the composed row, not of the key.
        $rows = [];
        foreach ($candidates as $key) {
            $row = $this->buildRow($key, $sourceLang, $targets);
            if ($this->rowMatchesStatus($row, $status, $targets)) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

        $pagination = $this->getPagination($request);
        $perPage = min($pagination['per_page'], self::MAX_PER_PAGE);
        $page = max(1, $pagination['page']);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return ApiResponse::paginated(
            $slice,
            count($rows),
            $page,
            $perPage,
            $this->getApiBaseUrl() . '/i18n/keys'
        );
    }

    /**
     * GET /i18n/keys/{key} — one key across every language.
     */
    public function key(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        $key = (string) $this->getRouteParam($request, 'key');
        if ($key === '') {
            throw new ValidationException('A translation key is required.');
        }

        $sourceLang = $this->queryLang($request, 'source_lang');

        return ApiResponse::create(
            $this->buildRow($key, $sourceLang, $this->sourceIndex()->languages())
        );
    }

    /**
     * GET /i18n/overrides/{lang} — the override file as raw YAML, for the
     * advanced editor. Optionally narrowed to the caller's current filter so
     * "edit as YAML" edits what is on screen rather than everything.
     */
    public function showOverrides(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        $lang = (string) $this->getRouteParam($request, 'lang');
        $params = $request->getQueryParams();
        $store = $this->store();
        $overrides = $store->overrides($lang);

        $namespace = isset($params['namespace']) ? (string) $params['namespace'] : null;
        if ($namespace !== null) {
            $prefix = $namespace . '.';
            $overrides = array_filter(
                $overrides,
                static fn(string $key): bool => $key === $namespace || str_starts_with($key, $prefix),
                ARRAY_FILTER_USE_KEY
            );

            return ApiResponse::create([
                'lang' => $lang,
                'scoped' => true,
                'namespace' => $namespace,
                'count' => count($overrides),
                'yaml' => $overrides === [] ? '' : \Grav\Common\Yaml::dump(
                    \Grav\Common\Utils::arrayUnflattenDotNotation($overrides),
                    10,
                    2
                ),
            ]);
        }

        return ApiResponse::create([
            'lang' => $lang,
            'scoped' => false,
            'namespace' => null,
            'count' => count($overrides),
            'yaml' => $store->raw($lang),
        ]);
    }

    /**
     * PATCH /i18n/overrides/{lang} — inline edits.
     *
     * Body: `{ "set": { "KEY": "value" }, "unset": ["KEY"] }`.
     *
     * A `set` whose value equals what the source ships is stored as a removal,
     * not as an override — see {@see TranslationOverrideStore} for why.
     */
    public function patchOverrides(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.write');
        $this->denyIfDemo($request, 'Translation overrides cannot be changed in demo mode.');

        $lang = (string) $this->getRouteParam($request, 'lang');
        $body = $this->getRequestBody($request);

        $set = $body['set'] ?? [];
        $unset = $body['unset'] ?? [];
        if (!is_array($set) || !is_array($unset)) {
            throw new ValidationException('`set` must be an object and `unset` an array of keys.');
        }
        if ($set === [] && $unset === []) {
            throw new ValidationException('Nothing to do: provide `set` and/or `unset`.');
        }

        try {
            $result = $this->store()->patch($lang, $set, array_values(array_filter($unset, 'is_string')));
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }

        $sourceLang = $this->queryLang($request, 'source_lang');
        $touched = array_values(array_unique(array_merge(
            array_keys($set),
            array_values(array_filter($unset, 'is_string'))
        )));

        $rows = [];
        foreach ($touched as $key) {
            $rows[] = $this->buildRow($key, $sourceLang, [$lang]);
        }

        return $this->respondWithInvalidation(
            $result + ['rows' => $rows],
            ['i18n:update', 'translations:update']
        );
    }

    /**
     * PUT /i18n/overrides/{lang} — advanced-mode whole-file save.
     *
     * Reports unknown keys rather than rejecting them. A typo'd key in the old
     * translation-strings plugin did nothing, forever, with no feedback; the
     * point here is to tell the user, not to refuse the save.
     */
    public function replaceOverrides(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.write');
        $this->denyIfDemo($request, 'Translation overrides cannot be changed in demo mode.');

        $lang = (string) $this->getRouteParam($request, 'lang');
        $body = $this->getRequestBody($request);

        if (!array_key_exists('yaml', $body) || !is_string($body['yaml'])) {
            throw new ValidationException('A `yaml` string is required.', [
                ['field' => 'yaml', 'message' => 'Required.'],
            ]);
        }

        // A namespace scope means "replace only these keys" — see the store for
        // why an unscoped save of a filtered view would be destructive.
        $namespace = isset($body['namespace']) && is_string($body['namespace']) && $body['namespace'] !== ''
            ? $body['namespace']
            : null;

        try {
            $result = $this->store()->replace($lang, $body['yaml'], $namespace);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        } catch (\RuntimeException $e) {
            throw new ValidationException('That YAML could not be parsed.', [
                ['field' => 'yaml', 'message' => $e->getMessage()],
            ]);
        }

        return $this->respondWithInvalidation($result, ['i18n:update', 'translations:update']);
    }

    /**
     * GET /i18n/translate — whether machine translation is available at all.
     *
     * The editor hides its translate actions entirely when ai-translate is
     * absent or unconfigured, rather than showing controls that error.
     */
    public function translateStatus(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        // Three ways to be unavailable, and they need three different answers:
        // buy/install it, switch it on, or give it a provider. Reporting one
        // flat `available: false` would leave the UI guessing, and the guess
        // most people would make ("go install it") is wrong two times out of
        // three for anyone who already has it.
        $installed = is_dir(GRAV_ROOT . '/user/plugins/ai-translate');
        $enabled = (bool) $this->config->get('plugins.ai-translate.enabled', false);
        $manager = $this->translationManager();

        return ApiResponse::create([
            'available' => $manager !== null,
            'installed' => $installed,
            'enabled' => $installed && $enabled,
            'reason' => match (true) {
                $manager !== null => null,
                !$installed => 'not_installed',
                !$enabled => 'not_enabled',
                default => 'not_configured',
            },
            'max_keys' => self::MAX_TRANSLATE_KEYS,
        ]);
    }

    /**
     * GET /i18n/import/translation-strings — what a site carried over from the
     * old plugin, and what importing it would do.
     *
     * Read-only and cheap enough to run on every visit to the editor, which is
     * the point: the site owner should not have to already know that a plugin
     * they installed years ago is now holding their overrides hostage.
     */
    public function importStatus(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.read');

        $report = $this->importer()->report();

        // The per-key detail is for a preview list, not a data dump — a site
        // with thousands of overrides shouldn't ship them all just to render a
        // card. Counts are exact regardless.
        foreach ($report['languages'] as $i => $language) {
            $report['languages'][$i]['keys'] = array_slice(
                $language['keys'],
                0,
                self::MAX_IMPORT_PREVIEW_KEYS
            );
        }

        return ApiResponse::create($report);
    }

    /**
     * POST /i18n/import/translation-strings — copy those overrides in.
     *
     * Deliberately does *not* disable the plugin. Turning it off is a config
     * write with its own permission and its own audit trail, so the client does
     * it through the normal config endpoint once this has succeeded. That also
     * keeps the only possible half-finished state a safe one: overrides present
     * in both places, with the plugin still winning and the site unchanged.
     */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.write');
        $this->denyIfDemo($request, 'Importing translation overrides is disabled in demo mode.');

        $importer = $this->importer();
        if ($importer->read() === []) {
            throw new ValidationException(
                'The translation-strings plugin has no overrides configured, so there is nothing to import.'
            );
        }

        $result = $importer->import();
        $result['plugin_enabled'] = $importer->pluginEnabled();

        return $this->respondWithInvalidation($result, ['translations:update']);
    }

    /**
     * POST /i18n/translate — propose machine translations. Never writes.
     *
     * Body: `{ source_lang, target_lang, keys: [...] }`.
     *
     * Proposals come back for the caller to review and then commit through the
     * normal PATCH. Bulk machine translation is where real damage happens, so
     * there is deliberately no path from here straight to disk.
     */
    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.translations.write');
        $this->denyIfDemo($request, 'Machine translation is disabled in demo mode.');

        $manager = $this->translationManager();
        if ($manager === null) {
            throw new ValidationException(
                'Machine translation needs the AI Translate plugin installed, enabled and configured with a provider.'
            );
        }

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['target_lang', 'keys']);

        $targetLang = (string) $body['target_lang'];
        $sourceLang = isset($body['source_lang']) ? (string) $body['source_lang'] : $this->defaultLang();
        $keys = is_array($body['keys']) ? array_values(array_filter($body['keys'], 'is_string')) : [];

        if ($keys === []) {
            throw new ValidationException('At least one key is required.');
        }
        if (count($keys) > self::MAX_TRANSLATE_KEYS) {
            throw new ValidationException(sprintf(
                'Too many keys in one request: %d, maximum %d.',
                count($keys),
                self::MAX_TRANSLATE_KEYS
            ));
        }
        if ($targetLang === $sourceLang) {
            throw new ValidationException('Source and target languages are the same.');
        }

        $guard = new TranslationPlaceholderGuard();
        $index = $this->sourceIndex();
        $store = $this->store();

        // Analyze everything first, then send only what is worth sending.
        $prepared = [];
        $proposals = [];
        foreach ($keys as $key) {
            $source = $store->overrides($sourceLang)[$key]
                ?? ($index->index($sourceLang)[$key]['value'] ?? null);

            if ($source === null || trim($source) === '') {
                $proposals[$key] = $this->proposal($key, null, null, 'no_source');
                continue;
            }

            $analysis = $guard->analyze($source);

            if ($analysis['needs_human']) {
                $proposals[$key] = $this->proposal($key, $source, null, 'icu_needs_human');
                continue;
            }
            if (!$analysis['translatable']) {
                $proposals[$key] = $this->proposal($key, $source, null, 'nothing_to_translate');
                continue;
            }

            $prepared[$key] = ['source' => $source, 'analysis' => $analysis];
        }

        if ($prepared !== []) {
            $masked = array_map(static fn(array $p): string => $p['analysis']['masked'], $prepared);

            try {
                $translated = $manager->translateBatch(array_values($masked), $targetLang, $sourceLang);
            } catch (\Throwable $e) {
                throw new ValidationException('The translation provider failed: ' . $e->getMessage());
            }

            $i = 0;
            foreach ($prepared as $key => $item) {
                $result = $translated[$i] ?? null;
                $i++;

                if (!is_string($result) || trim($result) === '') {
                    $proposals[$key] = $this->proposal($key, $item['source'], null, 'provider_returned_nothing');
                    continue;
                }

                // The check that turns a silent corruption into a visible refusal.
                if (!$guard->preserved($item['analysis']['masked'], $result)) {
                    $proposals[$key] = $this->proposal($key, $item['source'], null, 'placeholders_mangled');
                    continue;
                }

                $value = $guard->unmask($result, $item['analysis']['map']);
                $proposals[$key] = $this->proposal($key, $item['source'], $value, null);
            }
        }

        // Preserve the caller's key order so the client can zip proposals to rows.
        $ordered = [];
        foreach ($keys as $key) {
            if (isset($proposals[$key])) {
                $ordered[] = $proposals[$key];
            }
        }

        return ApiResponse::create([
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'proposals' => $ordered,
        ]);
    }

    // ─── internals ────────────────────────────────────────────────────

    /**
     * @return array{key: string, source: string|null, value: string|null, ok: bool, reason: string|null}
     */
    private function proposal(string $key, ?string $source, ?string $value, ?string $reason): array
    {
        return [
            'key' => $key,
            'source' => $source,
            'value' => $value,
            'ok' => $value !== null,
            'reason' => $reason,
        ];
    }

    /**
     * ai-translate's manager, or null when the plugin is missing, disabled, or
     * has no configured provider.
     */
    private function translationManager(): ?object
    {
        if (!$this->config->get('plugins.ai-translate.enabled', false)) {
            return null;
        }
        if (!class_exists(\Grav\Plugin\AiTranslate\TranslationManager::class)) {
            return null;
        }

        try {
            $manager = new \Grav\Plugin\AiTranslate\TranslationManager($this->grav, $this->config);

            return $manager->getProvider()->isConfigured() ? $manager : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Compose one matrix row: the source-language value plus a cell per target
     * language, each carrying its provenance.
     *
     * @param array<int, string> $targets
     * @return array<string, mixed>
     */
    private function buildRow(string $key, string $sourceLang, array $targets): array
    {
        $index = $this->sourceIndex();
        $entry = $index->index($sourceLang)[$key] ?? null;

        $cells = [];
        foreach ($targets as $lang) {
            $cells[$lang] = $this->buildCell($key, $lang);
        }

        return [
            'key' => $key,
            'namespace' => str_contains($key, '.') ? substr($key, 0, (int) strpos($key, '.')) : $key,
            'source_value' => $this->store()->overrides($sourceLang)[$key]
                ?? ($entry['value'] ?? null),
            'providers' => $entry['providers'] ?? [],
            'owner' => $entry['owner'] ?? null,
            'known' => $index->isKnownKey($key),
            'values' => $cells,
        ];
    }

    /**
     * One cell's value and provenance.
     *
     * `state` is the whole point of the editor: `shipped` came from a plugin or
     * theme, `overridden` is this site's own wording, and `missing` means the
     * user is really seeing a fallback. Without that distinction the editor is
     * just a text box.
     *
     * @return array{value: string|null, state: string, shipped: string|null}
     */
    private function buildCell(string $key, string $lang): array
    {
        $override = $this->store()->overrides($lang)[$key] ?? null;
        $shipped = $this->sourceIndex()->shippedValue($key, $lang);

        if ($override !== null) {
            return ['value' => $override, 'state' => 'overridden', 'shipped' => $shipped];
        }

        if ($shipped !== null) {
            return ['value' => $shipped, 'state' => 'shipped', 'shipped' => $shipped];
        }

        return ['value' => null, 'state' => 'missing', 'shipped' => null];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $targets
     */
    private function rowMatchesStatus(array $row, string $status, array $targets): bool
    {
        if ($status === '' || $status === 'all') {
            return true;
        }

        if ($status === 'unknown') {
            return $row['known'] === false;
        }

        foreach ($targets as $lang) {
            if (($row['values'][$lang]['state'] ?? null) === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, string>
     */
    private function requestedLanguages(array $params, string $sourceLang): array
    {
        $raw = (string) ($params['langs'] ?? '');
        if ($raw === '') {
            return [$sourceLang];
        }

        $available = $this->sourceIndex()->languages();
        $langs = [];
        foreach (explode(',', $raw) as $code) {
            $code = trim($code);
            if ($code !== '' && in_array($code, $available, true)) {
                $langs[$code] = true;
            }
        }

        return $langs === [] ? [$sourceLang] : array_keys($langs);
    }

    private function queryLang(ServerRequestInterface $request, string $param): string
    {
        $value = $request->getQueryParams()[$param] ?? null;
        if (is_string($value) && preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,4})?$/', $value)) {
            return $value;
        }

        return $this->defaultLang();
    }

    /**
     * The language the editor treats as the source of truth.
     *
     * Prefers the site's configured default; falls back to whatever the sources
     * actually ship, so a site whose default language has no strings at all
     * still opens on something populated rather than on an empty grid.
     */
    private function defaultLang(): string
    {
        $default = (string) $this->config->get('system.languages.default_lang', 'en');
        $available = $this->sourceIndex()->languages();

        if (in_array($default, $available, true)) {
            return $default;
        }

        return in_array('en', $available, true) ? 'en' : ($available[0] ?? 'en');
    }

    private function sourceIndex(): TranslationSourceIndex
    {
        return $this->sources ??= new TranslationSourceIndex($this->grav);
    }

    private function store(): TranslationOverrideStore
    {
        return $this->store ??= new TranslationOverrideStore($this->grav, $this->sourceIndex());
    }

    private function importer(): TranslationStringsImporter
    {
        return $this->importer ??= new TranslationStringsImporter(
            $this->grav,
            $this->sourceIndex(),
            $this->store()
        );
    }
}
