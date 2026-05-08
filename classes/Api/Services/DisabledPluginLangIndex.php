<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Yaml;

/**
 * Index of translation keys contributed exclusively by disabled plugins, keyed
 * by language code.
 *
 * Grav core's `Languages::flattenByLang()` reads every plugin's lang yaml
 * regardless of whether the plugin is enabled — fine for legacy admin, broken
 * for admin2 where a disabled plugin (most notably admin classic, mid-migration
 * on Grav 2 sites) would otherwise leak its strings into both the dictionary
 * served to the SPA and the server-side blueprint label resolver.
 *
 * This service walks `user/plugins/<name>/languages/<lang>.yaml` and
 * `user/plugins/<name>/languages.yaml` (single-file multi-lang format), buckets
 * keys by enabled-vs-disabled provenance, and returns the keys present only in
 * the disabled bucket. Keys also contributed by an enabled plugin are kept —
 * the enabled plugin owns them, even if a disabled plugin happens to ship the
 * same key.
 *
 * The result is cached per-language for the request lifecycle since the
 * underlying YAML files don't change mid-request.
 */
final class DisabledPluginLangIndex
{
    /** @var array<string, array<int, string>> */
    private array $cache = [];

    public function __construct(private readonly Grav $grav)
    {
    }

    /**
     * @return array<int, string> flat translation keys (e.g. `PLUGIN_ADMIN.ADD_FOLDER`)
     */
    public function disabledOnlyKeys(string $lang): array
    {
        if (isset($this->cache[$lang])) {
            return $this->cache[$lang];
        }

        $plugins = $this->grav['plugins'];
        $config = $this->grav['config'];
        $locator = $this->grav['locator'];

        $enabled = [];
        $disabled = [];

        foreach ($plugins as $plugin) {
            $name = $plugin->name;
            $resolved = $locator->findResource("plugin://{$name}");
            if (!$resolved || !is_dir($resolved)) {
                continue;
            }

            $keys = $this->collectPluginLangKeys($resolved, $lang);
            if (empty($keys)) {
                continue;
            }

            $isEnabled = (bool) $config->get("plugins.{$name}.enabled", false);
            foreach ($keys as $k) {
                if ($isEnabled) {
                    $enabled[$k] = true;
                } else {
                    $disabled[$k] = true;
                }
            }
        }

        $result = array_keys(array_diff_key($disabled, $enabled));
        $this->cache[$lang] = $result;
        return $result;
    }

    /**
     * True if `$key` is contributed only by disabled plugins for `$lang`.
     */
    public function isDisabledOnly(string $key, string $lang): bool
    {
        return in_array($key, $this->disabledOnlyKeys($lang), true);
    }

    /**
     * @return array<int, string>
     */
    private function collectPluginLangKeys(string $pluginDir, string $lang): array
    {
        $keys = [];

        $perLangFile = "{$pluginDir}/languages/{$lang}.yaml";
        if (is_file($perLangFile)) {
            $data = $this->safeParseYaml($perLangFile);
            if (is_array($data)) {
                foreach (array_keys(Utils::arrayFlattenDotNotation($data)) as $k) {
                    $keys[$k] = true;
                }
            }
        }

        $singleFile = "{$pluginDir}/languages.yaml";
        if (is_file($singleFile)) {
            $data = $this->safeParseYaml($singleFile);
            $langData = is_array($data) ? ($data[$lang] ?? null) : null;
            if (is_array($langData)) {
                foreach (array_keys(Utils::arrayFlattenDotNotation($langData)) as $k) {
                    $keys[$k] = true;
                }
            }
        }

        return array_keys($keys);
    }

    private function safeParseYaml(string $file): mixed
    {
        try {
            return Yaml::parse(file_get_contents($file));
        } catch (\Throwable) {
            return null;
        }
    }
}
