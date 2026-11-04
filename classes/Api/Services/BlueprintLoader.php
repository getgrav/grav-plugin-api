<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Data\Blueprint;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Throwable;

/**
 * Loads the blueprints the admin forms are built from, server side.
 *
 * BlueprintController serves these to admin-next; BlueprintUploadController
 * reads them back to learn what a `type: file` field really declares, instead
 * of trusting what the browser says the field declares. Both go through here
 * so the upload check sees the same blueprint the form was rendered from.
 */
class BlueprintLoader
{
    /**
     * Container types that don't add their own name to their children's names.
     * BlueprintController::serializeFields() uses the same list, which is what
     * gives admin-next the field names it sends back. `conditional` is a
     * wrapper too, as in the form plugin: its fields keep their own names
     * (grav-plugin-api#75).
     */
    public const LAYOUT_TYPES = ['tabs', 'tab', 'section', 'fieldset', 'columns', 'column', 'conditional', 'page-exists', 'elements', 'element'];

    public function __construct(
        private readonly Grav $grav,
    ) {}

    /**
     * Load a non-page blueprint (plugin, theme, account, group, config scope)
     * with its dynamic directives resolved.
     *
     * `Blueprint::load()` only parses and merges the YAML. It is `init()` that
     * walks the `data-*@` / `config-*@` / `security@` directives and applies
     * them, the same step core's own Blueprints::loadFile() performs
     * (grav-plugin-api#21).
     *
     * @param string $file Pass a `blueprints://` stream URL whenever the file
     *                     can be layered over by a site, so `extends@: parent@`
     *                     can still find its parents.
     */
    public function config(string $file): Blueprint
    {
        $blueprint = new Blueprint($file);
        $blueprint->load();

        try {
            $blueprint->init();
        } catch (Throwable $e) {
            // A third-party provider that throws must not take the whole form
            // down: serve what did resolve, but log it.
            $this->grav['log']->warning(sprintf(
                'API: blueprint "%s" failed to resolve its dynamic directives: %s',
                $file,
                $e->getMessage()
            ));
        }

        return $blueprint;
    }

    /**
     * Load a page template's blueprint through Pages::blueprints(), the path
     * admin-classic uses, falling back to `default` for an orphan template
     * (one the current theme defines no blueprint for).
     */
    public function page(string $template): ?Blueprint
    {
        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        try {
            $blueprint = $pages->blueprints($template);
        } catch (\RuntimeException) {
            return null;
        }

        if (!$blueprint->fields()) {
            try {
                $blueprint = $pages->blueprints('default');
            } catch (\RuntimeException) {
                return null;
            }
        }

        return $blueprint;
    }

    /**
     * Path of a plugin's config blueprint, or null when it has none.
     */
    public function pluginFile(string $slug): ?string
    {
        if (!self::isSlug($slug)) {
            return null;
        }
        $path = $this->grav['locator']->findResource("plugin://{$slug}");

        return $path && file_exists($path . '/blueprints.yaml') ? $path . '/blueprints.yaml' : null;
    }

    /**
     * Path of a theme's config blueprint, or null when it has none.
     */
    public function themeFile(string $slug): ?string
    {
        if (!self::isSlug($slug)) {
            return null;
        }
        $themesPath = $this->grav['locator']->findResource('themes://');
        $themePath = $themesPath . '/' . $slug;

        return is_dir($themePath) && file_exists($themePath . '/blueprints.yaml') ? $themePath . '/blueprints.yaml' : null;
    }

    /**
     * Stream URL of the account blueprint, or null when neither the site nor
     * the system provides one. Returns the stream URL, never the resolved
     * path, so a site override using `extends@: parent@` keeps its parents.
     */
    public function accountUri(): ?string
    {
        foreach (['blueprints://user/account.yaml', 'system://blueprints/user/account.yaml'] as $uri) {
            if ($this->grav['locator']->findResource($uri)) {
                return $uri;
            }
        }

        return null;
    }

    /**
     * The blueprint that owns a blueprint-upload `scope`, as its field tree.
     *
     *   plugins/<slug>  the plugin's config blueprint
     *   themes/<slug>   the theme's config blueprint
     *   pages/<route>   the blueprint of that page's template (what the page editor loads)
     *   users/<name>    the account blueprint
     *
     * Anything else (config scopes, plain paths, streams) owns no field this
     * lookup can vouch for, so it returns null.
     *
     * @return array<string, mixed>|null
     */
    public function fieldsForScope(string $scope): ?array
    {
        [$type, $name] = array_pad(explode('/', $scope, 2), 2, '');
        if ($name === '') {
            return null;
        }

        $blueprint = match ($type) {
            'plugins' => ($file = $this->pluginFile($name)) !== null ? $this->config($file) : null,
            'themes' => ($file = $this->themeFile($name)) !== null ? $this->config($file) : null,
            'users' => ($uri = $this->accountUri()) !== null ? $this->config($uri) : null,
            'pages' => $this->pageTemplateBlueprint($name),
            default => null,
        };

        $fields = $blueprint?->fields();

        return is_array($fields) ? $fields : null;
    }

    /**
     * Find a field in a blueprint field tree by the name admin-next knows it by.
     *
     * Walks tabs, sections, columns and other layout containers, and names
     * fields exactly as BlueprintController::serializeFields() does (dotted
     * prefixes, leading-dot relative names, `element` groups), so the name the
     * form sends back resolves to the same definition it was rendered from.
     *
     * @param array<string|int, mixed> $fields
     * @return array<string, mixed>|null
     */
    public static function findField(array $fields, string $name, string $prefix = '', string $parent = ''): ?array
    {
        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = $field['type'] ?? null;

            if (is_string($key) && isset($key[0]) && $key[0] === '.') {
                $base = $parent !== '' ? $parent : rtrim($prefix, '.');
                $fieldPath = $base !== '' ? $base . $key : substr($key, 1);
            } else {
                $fieldPath = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;
            }

            if ($fieldPath === $name && !in_array($type, self::LAYOUT_TYPES, true)) {
                return $field;
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                if ($type === 'element') {
                    $container = implode('.', array_slice(explode('.', rtrim($parent, '.')), 0, -1));
                    $elementBase = $container !== '' ? $container . '.' . $key : (string) $key;
                    $found = self::findField($field['fields'], $name, '', $elementBase);
                } else {
                    $childPrefix = in_array($type, self::LAYOUT_TYPES, true) ? $prefix : $fieldPath;
                    $found = self::findField($field['fields'], $name, $childPrefix, $fieldPath);
                }
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function pageTemplateBlueprint(string $route): ?Blueprint
    {
        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        /** @var PageInterface|null $page */
        $page = $pages->find('/' . ltrim($route, '/'));
        if ($page === null) {
            return null;
        }

        return $this->page((string) $page->template());
    }

    private static function isSlug(string $slug): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $slug) === 1;
    }
}
