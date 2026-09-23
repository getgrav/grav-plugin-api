<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;

/**
 * Index of translation keys contributed exclusively by disabled sources, keyed
 * by language code.
 *
 * Grav core's `Languages::flattenByLang()` reads every plugin's lang yaml
 * regardless of whether the plugin is enabled — fine for legacy admin, broken
 * for admin2 where a disabled plugin (most notably admin classic, mid-migration
 * on Grav 2 sites) would otherwise leak its strings into both the dictionary
 * served to the SPA and the server-side blueprint label resolver.
 *
 * The provenance walk this needs is {@see TranslationSourceIndex}, so this
 * class is now a thin filter over it: bucket each key by whether every provider
 * shipping it is disabled, and return the ones that are. Keys also contributed
 * by an enabled source are kept — the enabled source owns them, even if a
 * disabled one happens to ship the same key.
 *
 * Delegating also widened the net: inactive *themes* are now covered too. The
 * standalone walk this replaced only ever looked at plugins, so a switched-away
 * theme's strings still reached the SPA.
 *
 * The result is memoized per language for the request, and persisted in the
 * Grav cache against {@see TranslationSourceIndex::languageFingerprint()}, so a
 * warm request answers without loading the attribution index at all.
 */
final class DisabledPluginLangIndex
{
    private const CACHE_TTL = 604800;

    /** @var \WeakMap<Grav, self>|null */
    private static ?\WeakMap $shared = null;

    /** @var array<string, array<int, string>> */
    private array $cache = [];

    /** @var array<string, array<string, int>> lang => flipped key list, for isset lookups */
    private array $lookup = [];

    private ?TranslationSourceIndex $sources;

    public function __construct(private readonly Grav $grav, ?TranslationSourceIndex $sources = null)
    {
        $this->sources = $sources;
    }

    /**
     * The request-wide instance for a Grav container, sharing the request-wide
     * {@see TranslationSourceIndex}.
     */
    public static function shared(Grav $grav): self
    {
        self::$shared ??= new \WeakMap();

        return self::$shared[$grav] ??= new self($grav, TranslationSourceIndex::shared($grav));
    }

    /**
     * @return array<int, string> flat translation keys (e.g. `PLUGIN_ADMIN.ADD_FOLDER`)
     */
    public function disabledOnlyKeys(string $lang): array
    {
        if (isset($this->cache[$lang])) {
            return $this->cache[$lang];
        }

        $index = $this->sources();
        $cache = $this->grav['cache'];
        $cacheKey = 'api-i18n-disabled-' . $index->languageFingerprint($lang);
        $cached = $cache->fetch($cacheKey);
        if (is_array($cached)) {
            return $this->cache[$lang] = $cached;
        }

        $result = [];
        foreach ($index->index($lang) as $key => $entry) {
            foreach ($entry['providers'] as $providerId) {
                if ($index->isProviderEnabled($providerId)) {
                    // At least one enabled source ships it; not our problem.
                    continue 2;
                }
            }
            $result[] = $key;
        }

        $cache->save($cacheKey, $result, self::CACHE_TTL);

        return $this->cache[$lang] = $result;
    }

    /**
     * True if `$key` is contributed only by disabled plugins for `$lang`.
     */
    public function isDisabledOnly(string $key, string $lang): bool
    {
        $this->lookup[$lang] ??= array_flip($this->disabledOnlyKeys($lang));

        return isset($this->lookup[$lang][$key]);
    }

    private function sources(): TranslationSourceIndex
    {
        return $this->sources ??= new TranslationSourceIndex($this->grav);
    }
}
