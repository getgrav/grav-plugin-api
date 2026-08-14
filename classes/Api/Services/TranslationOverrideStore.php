<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Yaml;
use RocketTheme\Toolbox\File\YamlFile;

/**
 * Read/write access to this site's translation overrides, stored one file per
 * language at `user/languages/<lang>.yaml`.
 *
 * ## Why this file, and why it still needs a runtime merge
 *
 * `user://languages` is a first-class location in the `languages://` stream
 * (Grav's `Config\Setup::$streams`), and the compile-time merge in
 * `CompiledBase::loadFiles()` walks its sources in reverse order, so a value
 * here already outranks `system://languages` and every plugin. That is most of
 * what an override store needs, for free, in a plain diffable YAML file.
 *
 * What it does *not* outrank is a theme. Theme language files are absent from
 * the compile-time scan entirely — `Themes::loadLanguages()` merges them into
 * the finished object at theme-init time, after compilation — so a theme string
 * beats `user/languages`. Since theme strings are precisely what a site owner
 * most often wants to reword, {@see applyRuntime()} re-merges this file once
 * more at `onThemeInitialized`, which is the first point at which the theme has
 * had its say.
 *
 * ## Diff-only writes
 *
 * An override whose value equals what the source ships is not an override, it
 * is noise that silently rots when the plugin or theme changes its wording. Any
 * `set` matching {@see TranslationSourceIndex::shippedValue()} is stored as a
 * removal instead, so the file only ever contains real differences.
 */
final class TranslationOverrideStore
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(
        private readonly Grav $grav,
        private readonly TranslationSourceIndex $sources
    ) {
    }

    /**
     * Absolute path of the override file for a language, whether or not it exists.
     */
    public function path(string $lang): string
    {
        return $this->languageDir() . '/' . $this->safeLang($lang) . '.yaml';
    }

    /**
     * Flat dot-notation overrides for a language.
     *
     * @return array<string, string>
     */
    public function overrides(string $lang): array
    {
        $lang = $this->safeLang($lang);
        if (isset($this->cache[$lang])) {
            return $this->cache[$lang];
        }

        $file = $this->path($lang);
        if (!is_file($file)) {
            return $this->cache[$lang] = [];
        }

        $data = $this->parse((string) file_get_contents($file));

        return $this->cache[$lang] = is_array($data) ? $this->flatten($data) : [];
    }

    /**
     * The override file's raw YAML, for the advanced editor. Empty string when
     * the site has never overridden anything in this language.
     */
    public function raw(string $lang): string
    {
        $file = $this->path($lang);

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * Apply a set/unset patch to one language.
     *
     * @param array<string, string> $set   key => new value
     * @param array<int, string>    $unset keys to revert to shipped
     *
     * @return array{
     *     written: array<string, string>,
     *     removed: array<int, string>,
     *     reverted: array<int, string>,
     *     unknown: array<int, string>
     * } `reverted` are sets that matched the shipped value and so were dropped
     *   rather than stored; `unknown` are keys no source ships at all.
     */
    public function patch(string $lang, array $set, array $unset = []): array
    {
        $lang = $this->safeLang($lang);
        $current = $this->overrides($lang);

        $written = [];
        $removed = [];
        $reverted = [];
        $unknown = [];

        foreach ($unset as $key) {
            if (array_key_exists($key, $current)) {
                unset($current[$key]);
                $removed[] = $key;
            }
        }

        foreach ($set as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $value = (string) $value;

            if (!$this->sources->isKnownKey($key)) {
                $unknown[] = $key;
            }

            // Storing a value identical to the shipped one would silently stop
            // tracking the source's future wording changes. Treat it as a revert.
            if ($this->sources->shippedValue($key, $lang) === $value) {
                if (array_key_exists($key, $current)) {
                    unset($current[$key]);
                    $removed[] = $key;
                }
                $reverted[] = $key;
                continue;
            }

            $current[$key] = $value;
            $written[$key] = $value;
        }

        $this->write($lang, $current);

        return [
            'written' => $written,
            'removed' => array_values(array_unique($removed)),
            'reverted' => $reverted,
            'unknown' => $unknown,
        ];
    }

    /**
     * Replace a language's overrides from YAML text (advanced mode).
     *
     * With `$namespace` set, only keys under that namespace are replaced and
     * everything else in the file is left alone. This is what makes "edit as
     * YAML" safe to use on a filtered view: without it, saving a pane showing
     * one theme's strings would silently delete every override for every other
     * source.
     *
     * @return array{count: int, dropped: array<int, string>, unknown: array<int, string>, removed: int}
     * @throws \RuntimeException on unparseable YAML
     */
    public function replace(string $lang, string $yaml, ?string $namespace = null): array
    {
        $lang = $this->safeLang($lang);
        $existing = $this->overrides($lang);

        // Everything outside the edited scope survives untouched.
        $retained = $namespace === null
            ? []
            : array_filter(
                $existing,
                static fn(string $key): bool => !self::inNamespace($key, $namespace),
                ARRAY_FILTER_USE_KEY
            );
        $replacedCount = count($existing) - count($retained);

        $trimmed = trim($yaml);
        if ($trimmed === '') {
            $this->write($lang, $retained);

            return ['count' => 0, 'dropped' => [], 'unknown' => [], 'removed' => $replacedCount];
        }

        try {
            $data = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        // A YAML sequence parses to an array too, so `is_array` alone would let
        // `- one\n- two` through and silently store numeric keys.
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('Translation overrides must be a YAML mapping of keys to strings.');
        }

        $flat = $this->flatten($data);

        $dropped = [];
        $unknown = [];
        $outOfScope = [];
        foreach ($flat as $key => $value) {
            // Editing a scoped view must not be a back door to rewriting keys
            // the caller can't currently see.
            if ($namespace !== null && !self::inNamespace($key, $namespace)) {
                unset($flat[$key]);
                $outOfScope[] = $key;
                continue;
            }
            if (!$this->sources->isKnownKey($key)) {
                $unknown[] = $key;
            }
            if ($this->sources->shippedValue($key, $lang) === $value) {
                unset($flat[$key]);
                $dropped[] = $key;
            }
        }

        if ($outOfScope !== []) {
            throw new \RuntimeException(sprintf(
                'These keys are outside the %s scope you are editing: %s',
                $namespace,
                implode(', ', array_slice($outOfScope, 0, 5))
            ));
        }

        $this->write($lang, $retained + $flat);

        return [
            'count' => count($flat),
            'dropped' => $dropped,
            'unknown' => $unknown,
            'removed' => max(0, $replacedCount - count($flat)),
        ];
    }

    /** True when `$key` is the namespace itself or sits beneath it. */
    private static function inNamespace(string $key, string $namespace): bool
    {
        return $key === $namespace || str_starts_with($key, $namespace . '.');
    }

    /**
     * Languages this site has overrides for.
     *
     * @return array<int, string>
     */
    public function languages(): array
    {
        $dir = $this->languageDir();
        if (!is_dir($dir)) {
            return [];
        }

        $langs = [];
        foreach (glob("{$dir}/*.yaml") ?: [] as $file) {
            $langs[] = basename($file, '.yaml');
        }
        sort($langs);

        return $langs;
    }

    /**
     * Re-merge the override files into the live language set.
     *
     * Called from `onThemeInitialized`, the earliest point at which the active
     * theme has already merged its own strings — see the class docblock for why
     * the compile-time position is not enough.
     */
    public function applyRuntime(): void
    {
        $dir = $this->languageDir();
        if (!is_dir($dir)) {
            return;
        }

        $languages = $this->grav['languages'];
        $config = $this->grav['config'];

        // Only the languages this request can actually render, so a site with
        // thirty translations doesn't parse thirty files per page view.
        $language = $this->grav['language'];
        $default = strtolower((string) $config->get('system.languages.default_lang', 'en'));
        $active = strtolower((string) ($language->getActive() ?: $default));

        $codes = [$active];
        if (str_contains($active, '-')) {
            $codes[] = substr($active, 0, (int) strpos($active, '-'));
        }
        if (!in_array($default, $codes, true)) {
            $codes[] = $default;
        }

        // The admin renders in the user's own admin locale, which is a BCP 47
        // code that need not match any site content language.
        $adminLang = $this->adminLanguage();
        if ($adminLang !== null && !in_array($adminLang, $codes, true)) {
            $codes[] = $adminLang;
        }

        foreach ($codes as $code) {
            $overrides = $this->overrides($code);
            if ($overrides === []) {
                continue;
            }
            $languages->mergeRecursive([$code => Utils::arrayUnflattenDotNotation($overrides)]);
        }
    }

    /**
     * Persist a flat override map, or delete the file when nothing is left.
     *
     * @param array<string, string> $flat
     */
    private function write(string $lang, array $flat): void
    {
        $file = $this->path($lang);

        if ($flat === []) {
            if (is_file($file)) {
                @unlink($file);
            }
        } else {
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create {$dir}");
            }

            ksort($flat);
            $yaml = YamlFile::instance($file);
            $yaml->save(Utils::arrayUnflattenDotNotation($flat));
            $yaml->free();
        }

        $this->cache[$lang] = $flat;
        $this->sources->flush();
        $this->invalidateCompiledLanguages();
    }

    /**
     * Drop both language caches so the write is live on the next request.
     *
     * The compiled dictionary is the obvious one. The *file list* is the one
     * that bites: `ConfigServiceProvider::loadCachedFileList()` will skip its
     * mtime sweep entirely while the cache file is younger than the configured
     * check interval, so a newly created `user/languages/<lang>.yaml` can stay
     * undiscovered — the compiled set would rebuild without ever knowing the
     * file exists. Deleting both is the only guarantee.
     */
    private function invalidateCompiledLanguages(): void
    {
        $locator = $this->grav['locator'];
        $dir = $locator->findResource('cache://compiled/languages', true, true);
        if (!$dir || !is_dir($dir)) {
            return;
        }

        $environment = $this->grav['setup']->environment ?? '';
        foreach (["master-{$environment}.php", "filelist-languages-{$environment}.php"] as $name) {
            $path = "{$dir}/{$name}";
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * The admin UI locale for the current user, if the request is an admin one.
     */
    private function adminLanguage(): ?string
    {
        try {
            $user = $this->grav['user'] ?? null;
            $lang = $user ? $user->get('language') : null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($lang) && $lang !== '' ? $lang : null;
    }

    private function languageDir(): string
    {
        $locator = $this->grav['locator'];
        $dir = $locator->findResource('user://languages', true, true);

        return is_string($dir) ? $dir : GRAV_ROOT . '/user/languages';
    }

    /**
     * Reject anything that isn't a plain language code, so a key from the wire
     * can never escape `user/languages`.
     */
    private function safeLang(string $lang): string
    {
        if (!preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,4})?$/', $lang)) {
            throw new \InvalidArgumentException("Invalid language code: {$lang}");
        }

        return $lang;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, string>
     */
    private function flatten(array $data): array
    {
        $out = [];
        foreach (Utils::arrayFlattenDotNotation($data) as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    private function parse(string $yaml): mixed
    {
        try {
            return Yaml::parse($yaml);
        } catch (\Throwable) {
            return null;
        }
    }
}
