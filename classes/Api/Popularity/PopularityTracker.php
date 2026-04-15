<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Popularity;

use Grav\Common\Config\Config;
use Grav\Common\Grav;

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
            $ipHash = hash('sha1', (string) $grav['uri']->ip());
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
        } catch (\Throwable $e) {
            // Tracking must never break the page response — swallow.
        }
    }
}
