<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use Grav\Common\Data\Blueprints;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * Lightweight admin proxy registered as $grav['admin'] during API requests.
 *
 * Grav core checks `isset($grav['admin'])` in multiple places to alter
 * behavior: page routing/visibility, Flex authorization scope, blueprint
 * field handling, and event firing. Without this proxy, API-driven changes
 * operate in "site" scope rather than "admin" scope, causing subtle bugs:
 *
 *  - Non-routable/hidden pages invisible to API (Pages.php:1047)
 *  - Flex onAdminSave/AfterSave events don't fire (FlexGravTrait.php:60)
 *  - Blueprint edit mode not set (Page.php:1261)
 *  - Flex authorization uses 'site' scope instead of 'admin' (FlexObject.php)
 *  - Plugins checking isAdmin() return false
 *
 * This class implements the minimum interface that Grav core actually calls
 * on $grav['admin'], without pulling in the full admin plugin dependency.
 */
class AdminProxy
{
    /** @var string Admin base route (not applicable for API, but required by getRouteDetails) */
    public string $base = '';

    /** @var string Current location segment */
    public string $location = '';

    /** @var string Current route */
    public string $route = '';

    /** @var UserInterface The authenticated API user */
    public UserInterface $user;

    /** @var bool Whether multilang is enabled */
    public bool $multilang = false;

    /** @var string Active language */
    public string $language = '';

    /** @var string[] Enabled languages */
    public array $languages_enabled = [];

    private Grav $grav;
    private ?Blueprints $blueprintsLoader = null;

    /** @var array<string, PageInterface|null> Page cache */
    private array $pages = [];

    public function __construct(Grav $grav, UserInterface $user)
    {
        $this->grav = $grav;
        $this->user = $user;

        /** @var Language $language */
        $language = $grav['language'];
        $this->multilang = $language->enabled();
        if ($this->multilang) {
            $this->language = $language->getActive() ?? '';
            $this->languages_enabled = (array) $grav['config']->get('system.languages.supported', []);
        }
    }

    /**
     * Register this proxy as $grav['admin'].
     */
    public function register(): void
    {
        $this->grav['admin'] = $this;
    }

    /**
     * Get the current admin page (used by Pages.php and Plugin.php).
     *
     * In API context there's no "current admin page" being edited, so this
     * returns the page at the current route if set, or null.
     */
    public function page($route = false, $path = null): ?PageInterface
    {
        if (!$path) {
            $path = $this->route;
        }
        if ($route && !$path) {
            $path = '/';
        }
        if (!$path) {
            return null;
        }

        if (!isset($this->pages[$path])) {
            $this->pages[$path] = $this->getPage($path);
        }

        return $this->pages[$path];
    }

    /**
     * Find a page by path (used by Pages.php for parent resolution).
     */
    public function getPage(string $path): ?PageInterface
    {
        $pages = self::enablePages();

        if ($path && $path[0] !== '/') {
            $path = "/{$path}";
        }

        $path = urldecode($path);

        return $path ? $pages->find($path, true) : $pages->root();
    }

    /**
     * Return route details as [base, location, route] tuple.
     *
     * Used by Pages.php and AccountsServiceProvider.php to determine
     * which admin section is active. For API requests, we return empty
     * values since there's no admin page navigation happening.
     */
    public function getRouteDetails(): array
    {
        return [$this->base, $this->location, $this->route];
    }

    /**
     * Load a blueprint by type (used by Flex PageObject).
     */
    public function blueprints(string $type)
    {
        if ($this->blueprintsLoader === null) {
            $this->blueprintsLoader = new Blueprints('blueprints://');
        }

        return $this->blueprintsLoader->get($type);
    }

    /**
     * Translate a string using Grav's language system.
     *
     * This is a static method in the real Admin class, but core calls it
     * on the instance via $grav['admin']->translate().
     *
     * @param array|string $args
     * @param array|string|null $languages
     * @return string
     */
    public static function translate($args, $languages = null): string
    {
        $grav = Grav::instance();

        if (is_array($args)) {
            $lookup = array_shift($args);
        } else {
            $lookup = $args;
            $args = [];
        }

        if (!$languages) {
            if ($grav['config']->get('system.languages.translations_fallback', true)) {
                $languages = $grav['language']->getFallbackLanguages();
            } else {
                $languages = (array) $grav['language']->getDefault();
            }
            if (isset($grav['user']) && $grav['user']->authenticated) {
                $languages = [$grav['user']->language];
            }
        } else {
            $languages = (array) $languages;
        }

        foreach ((array) $languages as $lang) {
            $translation = $grav['language']->getTranslation($lang, $lookup, true);

            if (!$translation) {
                $language = $grav['language']->getDefault() ?: 'en';
                $translation = $grav['language']->getTranslation($language, $lookup, true);
            }

            if (!$translation) {
                $translation = $grav['language']->getTranslation('en', $lookup, true);
            }

            if ($translation) {
                if (count($args) >= 1) {
                    return vsprintf($translation, $args);
                }
                return $translation;
            }
        }

        return $lookup;
    }

    /**
     * Enable and return the Pages service.
     *
     * Mirrors Admin::enablePages() — ensures pages are initialized
     * (they are disabled by default during API requests for performance).
     */
    public static function enablePages(): Pages
    {
        static $pages;

        if ($pages) {
            return $pages;
        }

        $grav = Grav::instance();

        /** @var Pages $pages */
        $pages = $grav['pages'];
        $pages->enablePages();

        return $pages;
    }
}
