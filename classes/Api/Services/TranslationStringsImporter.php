<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Yaml;

/**
 * Move a site's overrides off the `translation-strings` plugin and into the
 * built-in editor's store.
 *
 * ## Why this exists
 *
 * `translation-strings` solved the same problem on Grav 1.7 by keeping each
 * language's overrides as a YAML *string* nested inside its own plugin config.
 * That works, but nothing can tell you whether a key in there still exists, so
 * a typo made in 2021 sits there doing nothing forever. The built-in editor
 * stores the same data at `user/languages/<lang>.yaml` — a real language file
 * Grav already understands — and can say which keys no source ships.
 *
 * ## The precedence trap this has to work around
 *
 * `translation-strings` merges its strings at `onThemeInitialized` with
 * priority **-1000**; the API plugin merges `user/languages` on the same event
 * at **0**. Lower priority runs later, so while both are active the *plugin*
 * merges last and wins. The practical effect is that a site with both switched
 * on can edit a string in the new editor, get a success response, and see no
 * change on the front end.
 *
 * So the order matters and is not interchangeable: **import first, confirm the
 * site still reads right, then disable the plugin.** Disabling first would drop
 * every override off the site for the length of the gap. Nothing here deletes
 * or rewrites the plugin's own config, so the move stays reversible.
 *
 * ## No stored "already migrated" flag
 *
 * Whether the import still has work to do is derived, not remembered: it is
 * simply whether any configured override is missing from `user/languages` or
 * disagrees with it. That keeps a stale marker from ever hiding real data, and
 * means a site that imports, reverts, and imports again behaves sensibly.
 */
final class TranslationStringsImporter
{
    public const SLUG = 'translation-strings';

    /** Will be written to user/languages. */
    public const NEW = 'new';

    /** Already in user/languages with the same value. */
    public const ALREADY = 'already';

    /** In user/languages with a *different* value; the plugin's copy wins. */
    public const CONFLICT = 'conflict';

    /** Equals what the source ships, so the store drops it as a no-op. */
    public const SHIPPED = 'shipped';

    /** @var array<string, array<string, string>>|null lang => key => value */
    private ?array $configured = null;

    public function __construct(
        private readonly Grav $grav,
        private readonly TranslationSourceIndex $sources,
        private readonly TranslationOverrideStore $store
    ) {
    }

    /**
     * Is the plugin currently switched on? While it is, it outranks everything
     * this editor writes, so the UI has to say so even after a clean import.
     */
    public function pluginEnabled(): bool
    {
        return (bool) $this->grav['config']->get('plugins.' . self::SLUG . '.enabled', false);
    }

    /**
     * Path of the plugin config the overrides are read from, whether or not it
     * exists — a site may be running entirely off the plugin's shipped defaults.
     */
    public function configPath(): string
    {
        return $this->userConfigPath();
    }

    /**
     * The full picture: what is configured, what importing it would do, and
     * whether there is anything left to do at all.
     *
     * @return array{
     *     present: bool,
     *     plugin_enabled: bool,
     *     config_path: string,
     *     pending: int,
     *     total: int,
     *     languages: array<int, array{
     *         code: string, total: int, new: int, already: int,
     *         conflict: int, shipped: int, unknown: int,
     *         keys: array<int, array{key: string, status: string, unknown: bool, current: ?string, value: string}>
     *     }>
     * }
     */
    public function report(): array
    {
        $languages = [];
        $pending = 0;
        $total = 0;

        foreach ($this->read() as $lang => $overrides) {
            if ($overrides === []) {
                continue;
            }

            $existing = $this->store->overrides($lang);
            $counts = [self::NEW => 0, self::ALREADY => 0, self::CONFLICT => 0, self::SHIPPED => 0];
            $unknownCount = 0;
            $keys = [];

            foreach ($overrides as $key => $value) {
                $status = $this->statusFor($lang, $key, $value, $existing);
                $counts[$status]++;

                $unknown = !$this->sources->isKnownKey($key);
                if ($unknown) {
                    $unknownCount++;
                }

                $keys[] = [
                    'key' => $key,
                    'status' => $status,
                    'unknown' => $unknown,
                    'current' => $existing[$key] ?? null,
                    'value' => $value,
                ];
            }

            $total += count($overrides);
            $pending += $counts[self::NEW] + $counts[self::CONFLICT];

            $languages[] = [
                'code' => $lang,
                'total' => count($overrides),
                'new' => $counts[self::NEW],
                'already' => $counts[self::ALREADY],
                'conflict' => $counts[self::CONFLICT],
                'shipped' => $counts[self::SHIPPED],
                'unknown' => $unknownCount,
                'keys' => $keys,
            ];
        }

        return [
            'present' => $languages !== [],
            'plugin_enabled' => $this->pluginEnabled(),
            'config_path' => $this->configPath(),
            'pending' => $pending,
            'total' => $total,
            'languages' => $languages,
        ];
    }

    /**
     * Copy every configured override into `user/languages`, merging rather than
     * replacing so hand-written overrides already in those files survive.
     *
     * Where both stores name the same key with different values the plugin's
     * value wins, because that is what the site is currently rendering — an
     * import that changed what visitors see would be a surprise. The report
     * names those keys so the choice is visible rather than silent.
     *
     * @return array{
     *     imported: int, reverted: int, unknown: array<int, string>,
     *     languages: array<int, array{code: string, written: int, reverted: int, unknown: int, path: string}>
     * }
     */
    public function import(): array
    {
        $imported = 0;
        $reverted = 0;
        $unknown = [];
        $languages = [];

        foreach ($this->read() as $lang => $overrides) {
            if ($overrides === []) {
                continue;
            }

            $result = $this->store->patch($lang, $overrides);

            $imported += count($result['written']);
            $reverted += count($result['reverted']);
            $unknown = array_merge($unknown, $result['unknown']);

            $languages[] = [
                'code' => $lang,
                'written' => count($result['written']),
                'reverted' => count($result['reverted']),
                'unknown' => count($result['unknown']),
                'path' => $this->store->path($lang),
            ];
        }

        return [
            'imported' => $imported,
            'reverted' => $reverted,
            'unknown' => array_values(array_unique($unknown)),
            'languages' => $languages,
        ];
    }

    /**
     * Configured overrides, flattened to `lang => dotted key => value`.
     *
     * @return array<string, array<string, string>>
     */
    public function read(): array
    {
        if ($this->configured !== null) {
            return $this->configured;
        }

        $configured = $this->grav['config']->get('plugins.' . self::SLUG . '.languages');

        // Fall back to the config file on disk. A 1.7 → 2.0 migration under the
        // "skip" policy deletes `user/plugins/<slug>/` but keeps the config, and
        // a plugin scope with no plugin behind it is exactly the case where
        // relying on the config service is least safe.
        if (!is_array($configured) || $configured === []) {
            $configured = $this->readConfigFile();
        }

        if (!is_array($configured) || $configured === []) {
            return $this->configured = [];
        }

        return $this->configured = $this->normalize($configured);
    }

    /**
     * @param array<string, string> $existing
     */
    private function statusFor(string $lang, string $key, string $value, array $existing): string
    {
        if ($this->sources->shippedValue($key, $lang) === $value) {
            return self::SHIPPED;
        }

        if (!array_key_exists($key, $existing)) {
            return self::NEW;
        }

        return $existing[$key] === $value ? self::ALREADY : self::CONFLICT;
    }

    /**
     * @return array<mixed>
     */
    private function readConfigFile(): array
    {
        $file = $this->userConfigPath();
        if (!is_file($file)) {
            return [];
        }

        try {
            $data = Yaml::parse((string) file_get_contents($file));
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) && is_array($data['languages'] ?? null) ? $data['languages'] : [];
    }

    private function userConfigPath(): string
    {
        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];

        return $locator->findResource('user://config', true, true) . '/plugins/' . self::SLUG . '.yaml';
    }

    /**
     * Normalize both storage shapes the plugin has used: a list of
     * `{code, content}` entries, and a plain `code: {...}` map. `content` may be
     * a YAML string (as typed into its CodeMirror field) or already parsed.
     *
     * @param array<mixed> $configured
     * @return array<string, array<string, string>>
     */
    private function normalize(array $configured): array
    {
        $out = [];

        foreach ($configured as $codeKey => $entry) {
            if (is_string($codeKey) && is_array($entry)) {
                $code = strtolower(trim($codeKey));
                if ($code !== '') {
                    $out[$code] = ($out[$code] ?? []) + $this->flatten($entry);
                }
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $code = strtolower(trim((string) ($entry['code'] ?? '')));
            if ($code === '') {
                continue;
            }

            $content = $entry['content'] ?? [];
            if (is_string($content)) {
                try {
                    $content = Yaml::parse($content) ?? [];
                } catch (\Throwable) {
                    continue;
                }
            }

            if (is_array($content)) {
                $out[$code] = ($out[$code] ?? []) + $this->flatten($content);
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, string>
     */
    private function flatten(array $data): array
    {
        $flat = [];
        foreach (Utils::arrayFlattenDotNotation($data) as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $flat[$key] = (string) $value;
            }
        }

        return $flat;
    }
}
