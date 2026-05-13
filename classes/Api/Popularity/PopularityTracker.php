<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Popularity;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Yaml;

/**
 * Records page views into PopularityStore. Mirrors the behaviour of
 * admin-classic's tracker (bot/DNT respect, configurable ignore globs)
 * but writes to a SQLite database instead of four JSON files.
 */
class PopularityTracker
{
    private Config $config;
    private PopularityStore $store;

    public function __construct(?PopularityStore $store = null)
    {
        $this->config = Grav::instance()['config'];
        $this->store = $store ?? new PopularityStore();
    }

    public function trackHit(): void
    {
        if (!$this->config->get('plugins.api.popularity.enabled', true)) {
            return;
        }

        $grav = Grav::instance();

        if (!$grav['browser']->isHuman()) {
            return;
        }
        if (!$grav['browser']->isTrackable()) {
            return;
        }

        /** @var \Grav\Common\Page\Interfaces\PageInterface|null $page */
        $page = $grav['page'] ?? null;
        if ($page === null || !$page->route()) {
            return;
        }
        if ($page->template() === 'error') {
            return;
        }

        $route = $page->route();
        $url = (string) str_replace($grav['base_url_relative'], '', $page->url());

        foreach ((array) $this->config->get('plugins.api.popularity.ignore', []) as $ignore) {
            if (fnmatch((string) $ignore, $url)) {
                return;
            }
        }

        try {
            // Keyed HMAC over the visitor IP with a server-private salt.
            // GDPR Recital 26 / Art. 4(1): plain sha1(ip) is reversible via
            // a precomputed rainbow table of the ~4.3B IPv4 space (trivial
            // on a modern GPU), so the hash remains personal data. Keying
            // with a per-install secret the attacker can't compute against
            // breaks that re-identification path while preserving stable
            // bucketing for the unique-visitor counter.
            $ipHash = hash_hmac('sha256', (string) $grav['uri']->ip(), $this->getSalt());
            // Pruning happens inside recordHit() under the same lock — every
            // write trims to the configured retention window, so the file
            // can never grow beyond bounded size between hits.
            $this->store->recordHit(
                $route,
                $ipHash,
                null,
                (int) $this->config->get('plugins.api.popularity.history.daily', 30),
                (int) $this->config->get('plugins.api.popularity.history.monthly', 12),
                (int) $this->config->get('plugins.api.popularity.history.visitors', 20),
            );
        } catch (\Throwable) {
            // Tracking must never break the page response — swallow.
        }
    }

    /**
     * Read the popularity HMAC salt from config, auto-generating + persisting
     * one on first use. The salt MUST stay stable across requests so the
     * unique-visitor bucket for a given IP stays the same; regenerating per
     * request would balloon the visitors map with duplicate entries.
     *
     * Stored under plugins.api.popularity.salt in user/config/plugins/api.yaml.
     * Never shipped with a default — a committed salt would be globally known
     * and defeat the keyed-hash protection entirely.
     */
    private function getSalt(): string
    {
        $salt = (string) $this->config->get('plugins.api.popularity.salt', '');
        if ($salt !== '') {
            return $salt;
        }

        $salt = bin2hex(random_bytes(32));
        $this->config->set('plugins.api.popularity.salt', $salt);

        // Persist so subsequent requests reuse the same salt. If we can't
        // write the file (perms, missing config stream), fall through with
        // the in-memory salt — tracking still works for this request and we
        // retry on the next hit.
        $grav = Grav::instance();
        $locator = $grav['locator'];
        $file = $locator->findResource('config://plugins/api.yaml');
        if (!$file) {
            $configDir = $locator->findResource('config://', true);
            if (!$configDir) {
                if (isset($grav['log'])) {
                    $grav['log']->warning('api.popularity: could not resolve config:// stream to persist popularity salt; visitor counts may double until salt is configured.');
                }
                return $salt;
            }
            $file = $configDir . '/plugins/api.yaml';
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            if (isset($grav['log'])) {
                $grav['log']->warning(sprintf('api.popularity: could not create %s to persist popularity salt.', $dir));
            }
            return $salt;
        }

        $yaml = Yaml::parse(file_exists($file) ? (string) file_get_contents($file) : '') ?? [];
        $yaml['popularity']['salt'] = $salt;
        if (@file_put_contents($file, Yaml::dump($yaml)) === false) {
            if (isset($grav['log'])) {
                $grav['log']->warning(sprintf('api.popularity: could not write popularity salt to %s — visitor counts may double until next successful write.', $file));
            }
        }

        return $salt;
    }
}
