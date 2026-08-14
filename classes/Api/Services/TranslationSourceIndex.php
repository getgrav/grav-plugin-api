<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use DirectoryIterator;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Yaml;

/**
 * Provenance index for translation strings: which source shipped which key, in
 * which language, with what value.
 *
 * Grav's compiled `$grav['languages']` object is a single flattened merge with
 * no record of where anything came from, so nothing downstream can answer
 * "is this string the theme's, or did this site override it?". This service
 * rebuilds that attribution by walking each source's own language files:
 *
 *   - `system://languages/<lang>.yaml`                  → `system:core`
 *   - `user/plugins/<slug>/languages/<lang>.yaml`       → `plugin:<slug>`
 *   - `user/themes/<slug>/languages/<lang>.yaml`        → `theme:<slug>`
 *   - `user://languages/<lang>.yaml`                    → `user:overrides`
 *
 * Each source is also read in the single-file `languages.yaml` form (a
 * multi-language map keyed by code), which older plugins and themes still use.
 *
 * ## Precedence
 *
 * The order in `PRECEDENCE` mirrors what actually happens at runtime, which is
 * not the order the stream definitions suggest. Compile-time merging
 * (`ConfigServiceProvider::languages()` → `CompiledBase::loadFiles()`) covers
 * only `languages://` and `plugins://`, giving user > system > plugins. Themes
 * are not in that scan at all — `Themes::loadLanguages()` merges them
 * separately at theme-init time, which is *after* the compiled object is built,
 * so a theme string beats `user/languages`. The site's own overrides only win
 * because {@see TranslationOverrideStore} re-merges them later still.
 *
 * `shippedValue()` deliberately excludes `user:overrides`, so callers can show
 * "what this would say if you hadn't changed it" next to the current value.
 *
 * Results are cached per language against a fingerprint of every scanned file's
 * mtime, so an edit to any language file invalidates the entry immediately.
 */
final class TranslationSourceIndex
{
    public const KIND_SYSTEM = 'system';
    public const KIND_PLUGIN = 'plugin';
    public const KIND_THEME = 'theme';
    public const KIND_USER = 'user';

    public const PROVIDER_SYSTEM = 'system:core';
    public const PROVIDER_USER = 'user:overrides';

    /**
     * Highest precedence last, matching the runtime merge order documented above.
     *
     * @var array<int, string>
     */
    private const PRECEDENCE = [self::KIND_PLUGIN, self::KIND_SYSTEM, self::KIND_USER, self::KIND_THEME];

    private const CACHE_TTL = 604800; // 7 days; entries are keyed by mtime fingerprint anyway

    /**
     * Bump when the shape of a cached index entry or the precedence rules change.
     * The fingerprint only covers the *files*, so without this a code change
     * would keep serving entries built by the previous logic.
     */
    private const CACHE_VERSION = 1;

    /** @var array<string, array<string, array{value: string, owner: string, providers: array<int, string>}>> */
    private array $indexCache = [];

    /** @var array<string, array{id: string, kind: string, slug: string, label: string, enabled: bool, path: string}>|null */
    private ?array $providerCache = null;

    /** @var array<string, array<int, array{provider: string, file: string, lang_key: string|null}>>|null */
    private ?array $fileMapCache = null;

    public function __construct(private readonly Grav $grav)
    {
    }

    /**
     * Every source that contributes at least one language file, keyed by provider id.
     *
     * @return array<string, array{id: string, kind: string, slug: string, label: string, enabled: bool, path: string}>
     */
    public function providers(): array
    {
        if ($this->providerCache !== null) {
            return $this->providerCache;
        }

        $locator = $this->grav['locator'];
        $config = $this->grav['config'];
        $providers = [];

        foreach ((array) $locator->findResources('system://languages') as $path) {
            if (is_dir($path)) {
                $providers[self::PROVIDER_SYSTEM] = [
                    'id' => self::PROVIDER_SYSTEM,
                    'kind' => self::KIND_SYSTEM,
                    'slug' => 'core',
                    'label' => 'Grav Core',
                    'enabled' => true,
                    'path' => $path,
                ];
                break;
            }
        }

        foreach ($this->scanExtensionDirs('plugins://') as $slug => $dir) {
            if (!$this->hasLanguageFiles($dir)) {
                continue;
            }
            $providers["plugin:{$slug}"] = [
                'id' => "plugin:{$slug}",
                'kind' => self::KIND_PLUGIN,
                'slug' => $slug,
                'label' => $this->extensionLabel($dir, $slug),
                'enabled' => (bool) $config->get("plugins.{$slug}.enabled", false),
                'path' => $dir,
            ];
        }

        $activeTheme = (string) $config->get('system.pages.theme', '');
        foreach ($this->scanExtensionDirs('themes://') as $slug => $dir) {
            if (!$this->hasLanguageFiles($dir)) {
                continue;
            }
            $providers["theme:{$slug}"] = [
                'id' => "theme:{$slug}",
                'kind' => self::KIND_THEME,
                'slug' => $slug,
                'label' => $this->extensionLabel($dir, $slug),
                'enabled' => $slug === $activeTheme,
                'path' => $dir,
            ];
        }

        foreach ((array) $locator->findResources('user://languages') as $path) {
            if (is_dir($path)) {
                $providers[self::PROVIDER_USER] = [
                    'id' => self::PROVIDER_USER,
                    'kind' => self::KIND_USER,
                    'slug' => 'overrides',
                    'label' => 'This Site',
                    'enabled' => true,
                    'path' => $path,
                ];
                break;
            }
        }

        $this->providerCache = $providers;
        return $providers;
    }

    /**
     * Language codes any source ships a file for.
     *
     * Codes are returned exactly as they appear on disk. Grav core, plugins and
     * themes use short codes (`en`, `fr`); admin2 uses BCP 47 (`en-US`,
     * `fr-FR`). Both are real, separate top-level keys in the compiled language
     * set, so neither is normalized away here.
     *
     * @return array<int, string>
     */
    public function languages(): array
    {
        $langs = array_keys($this->fileMap());
        sort($langs);

        return $langs;
    }

    /**
     * Attribution for one language: key => shipped value, owning provider, and
     * every provider that contributes the key.
     *
     * The `value` is the winning value under {@see PRECEDENCE}, and `owner` the
     * provider it came from. `providers` is ordered lowest to highest precedence.
     *
     * @return array<string, array{value: string, owner: string, providers: array<int, string>}>
     */
    public function index(string $lang): array
    {
        if (isset($this->indexCache[$lang])) {
            return $this->indexCache[$lang];
        }

        $sources = $this->fileMap()[$lang] ?? [];
        if ($sources === []) {
            return $this->indexCache[$lang] = [];
        }

        $cache = $this->grav['cache'];
        $cacheKey = 'api-i18n-index-' . md5(self::CACHE_VERSION . '|' . $lang . '|' . $this->fingerprint($sources));
        $cached = $cache->fetch($cacheKey);
        if (is_array($cached)) {
            return $this->indexCache[$lang] = $cached;
        }

        $providers = $this->providers();
        $index = [];

        // Sort sources so that higher-precedence ones are applied last, and a
        // source's own value simply overwrites what came before it.
        //
        // Enabled-ness outranks kind. Only the *active* theme's languages are
        // merged at runtime (`Themes::loadLanguages()` reads `theme://languages`,
        // which resolves to the active theme alone) and a disabled plugin's
        // strings are filtered out of the dictionary downstream — so an inactive
        // theme must never win the value, even though it outranks plugins by kind.
        // Disabled sources stay in the index because callers need to know they
        // contribute the key at all; they just lose every precedence contest.
        usort($sources, function (array $a, array $b) use ($providers): int {
            $rank = static function (array $source) use ($providers): array {
                $provider = $providers[$source['provider']] ?? null;
                $kindRank = array_search($provider['kind'] ?? '', self::PRECEDENCE, true);

                return [(int) ($provider['enabled'] ?? false), $kindRank === false ? -1 : $kindRank];
            };

            return $rank($a) <=> $rank($b);
        });

        foreach ($sources as $source) {
            foreach ($this->readFile($source['file'], $source['lang_key']) as $key => $value) {
                if (isset($index[$key])) {
                    $index[$key]['value'] = $value;
                    $index[$key]['owner'] = $source['provider'];
                    if (!in_array($source['provider'], $index[$key]['providers'], true)) {
                        $index[$key]['providers'][] = $source['provider'];
                    }
                    continue;
                }

                $index[$key] = [
                    'value' => $value,
                    'owner' => $source['provider'],
                    'providers' => [$source['provider']],
                ];
            }
        }

        $cache->save($cacheKey, $index, self::CACHE_TTL);

        return $this->indexCache[$lang] = $index;
    }

    /**
     * The value a key would have without this site's overrides, or null if no
     * source other than `user:overrides` ships it.
     */
    public function shippedValue(string $key, string $lang): ?string
    {
        $entry = $this->index($lang)[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry['owner'] !== self::PROVIDER_USER) {
            return $entry['value'];
        }

        // The site overrode it in the compile-time file as well; fall back to the
        // highest-precedence provider that isn't us.
        $others = array_values(array_filter(
            $entry['providers'],
            static fn(string $id): bool => $id !== self::PROVIDER_USER
        ));

        return $others === [] ? null : $this->valueFromProvider($key, $lang, end($others));
    }

    /**
     * Keys a given provider contributes for a language, as key => value.
     *
     * @return array<string, string>
     */
    public function keysForProvider(string $providerId, string $lang): array
    {
        $out = [];
        foreach ($this->fileMap()[$lang] ?? [] as $source) {
            if ($source['provider'] !== $providerId) {
                continue;
            }
            $out += $this->readFile($source['file'], $source['lang_key']);
        }

        return $out;
    }

    /**
     * Every key any source ships in any language — the universe a "missing in
     * &lt;lang&gt;" check is measured against.
     *
     * @return array<int, string>
     */
    public function allKeys(?string $sourceLang = null): array
    {
        if ($sourceLang !== null) {
            return array_keys($this->index($sourceLang));
        }

        $keys = [];
        foreach ($this->languages() as $lang) {
            $keys += $this->index($lang);
        }

        return array_keys($keys);
    }

    /**
     * True if no source ships this key in any language — i.e. an override for it
     * is almost certainly a typo.
     */
    public function isKnownKey(string $key, ?string $sourceLang = null): bool
    {
        foreach ($sourceLang !== null ? [$sourceLang] : $this->languages() as $lang) {
            $entry = $this->index($lang)[$key] ?? null;
            if ($entry === null) {
                continue;
            }
            // An override-only key is not evidence that anything ships it.
            if ($entry['providers'] !== [self::PROVIDER_USER]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Namespaces (first dot-notation segment) a provider contributes, with counts.
     *
     * @return array<string, int>
     */
    public function namespacesForProvider(string $providerId, string $lang): array
    {
        $namespaces = [];
        foreach (array_keys($this->keysForProvider($providerId, $lang)) as $key) {
            $ns = strpos($key, '.') === false ? $key : substr($key, 0, strpos($key, '.'));
            $namespaces[$ns] = ($namespaces[$ns] ?? 0) + 1;
        }
        ksort($namespaces);

        return $namespaces;
    }

    /**
     * Drop memoized state so a write is reflected without a new request.
     */
    public function flush(): void
    {
        $this->indexCache = [];
        $this->providerCache = null;
        $this->fileMapCache = null;
    }

    /**
     * Language file inventory: lang => list of {provider, file, lang_key}.
     *
     * `lang_key` is null for a per-language file and the language code for the
     * single-file `languages.yaml` form, where the language is a top-level key.
     *
     * @return array<string, array<int, array{provider: string, file: string, lang_key: string|null}>>
     */
    private function fileMap(): array
    {
        if ($this->fileMapCache !== null) {
            return $this->fileMapCache;
        }

        $map = [];

        foreach ($this->providers() as $id => $provider) {
            $base = $provider['path'];
            // The system and user providers point at the languages folder itself;
            // plugins and themes point at the extension root.
            $langDir = in_array($provider['kind'], [self::KIND_SYSTEM, self::KIND_USER], true)
                ? $base
                : "{$base}/languages";

            if (is_dir($langDir)) {
                foreach (new DirectoryIterator($langDir) as $file) {
                    if ($file->isDot() || $file->isDir() || $file->getExtension() !== 'yaml') {
                        continue;
                    }
                    $lang = $file->getBasename('.yaml');
                    $map[$lang][] = ['provider' => $id, 'file' => $file->getPathname(), 'lang_key' => null];
                }
            }

            // The single-file multi-language form only exists for plugins and
            // themes; core and `user://languages` are per-language files only.
            $single = "{$base}/languages.yaml";

            if (!in_array($provider['kind'], [self::KIND_SYSTEM, self::KIND_USER], true) && is_file($single)) {
                $data = $this->safeParseYaml($single);
                foreach (is_array($data) ? array_keys($data) : [] as $lang) {
                    if (is_string($lang) && is_array($data[$lang])) {
                        $map[$lang][] = ['provider' => $id, 'file' => $single, 'lang_key' => $lang];
                    }
                }
            }
        }

        return $this->fileMapCache = $map;
    }

    /**
     * Flat dot-notation key => value for one language file.
     *
     * Only scalar leaves are kept. A lang file that nests a list under a key
     * flattens to numeric segments, which no translation lookup would ever hit.
     *
     * @param string|null $langKey Top-level language key for the single-file form, null for per-language files.
     * @return array<string, string>
     */
    private function readFile(string $file, ?string $langKey): array
    {
        $data = $this->safeParseYaml($file);
        if (!is_array($data)) {
            return [];
        }

        if ($langKey !== null) {
            $data = $data[$langKey] ?? null;
            if (!is_array($data)) {
                return [];
            }
        }

        $out = [];
        foreach (Utils::arrayFlattenDotNotation($data) as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Value of a key as shipped by one specific provider.
     */
    private function valueFromProvider(string $key, string $lang, string $providerId): ?string
    {
        return $this->keysForProvider($providerId, $lang)[$key] ?? null;
    }

    /**
     * @param array<int, array{provider: string, file: string, lang_key: string|null}> $sources
     */
    private function fingerprint(array $sources): string
    {
        $parts = [];
        foreach ($sources as $source) {
            $parts[] = $source['file'] . ':' . (@filemtime($source['file']) ?: 0);
        }
        sort($parts);

        return md5(implode('|', $parts));
    }

    /**
     * Extension slug => absolute path, across every resolved stream location.
     *
     * @return array<string, string>
     */
    private function scanExtensionDirs(string $stream): array
    {
        $dirs = [];
        foreach ((array) $this->grav['locator']->findResources($stream) as $path) {
            if (!is_dir($path)) {
                continue;
            }
            foreach (new DirectoryIterator($path) as $dir) {
                if ($dir->isDot() || !$dir->isDir()) {
                    continue;
                }
                // First stream location wins, matching locator precedence.
                $dirs[$dir->getFilename()] ??= $dir->getPathname();
            }
        }
        ksort($dirs);

        return $dirs;
    }

    private function hasLanguageFiles(string $dir): bool
    {
        return is_dir("{$dir}/languages") || is_file("{$dir}/languages.yaml");
    }

    /**
     * Human-readable name from the extension's blueprints, falling back to the slug.
     */
    private function extensionLabel(string $dir, string $slug): string
    {
        $blueprint = "{$dir}/blueprints.yaml";
        if (is_file($blueprint)) {
            $data = $this->safeParseYaml($blueprint);
            $name = is_array($data) ? ($data['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return ucwords(str_replace('-', ' ', $slug));
    }

    private function safeParseYaml(string $file): mixed
    {
        try {
            return Yaml::parse(file_get_contents($file) ?: '');
        } catch (\Throwable) {
            return null;
        }
    }
}
