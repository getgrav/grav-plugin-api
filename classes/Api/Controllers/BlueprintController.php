<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Data\Blueprint;
use Grav\Common\Page\Pages;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\DisabledPluginLangIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

class BlueprintController extends AbstractApiController
{
    private ?DisabledPluginLangIndex $disabledLangIndex = null;

    private function disabledLangIndex(): DisabledPluginLangIndex
    {
        return $this->disabledLangIndex ??= new DisabledPluginLangIndex($this->grav);
    }

    /**
     * Whitelist of callable patterns allowed by the resolve endpoint.
     * Only static methods from known Grav namespaces are permitted.
     */
    private const RESOLVE_ALLOWED_NAMESPACES = [
        'Grav\\Common\\',
        'Grav\\Plugin\\',
    ];

    /**
     * GET /data/resolve?callable=\Grav\Common\Page\Pages::pageTypes
     *
     * Generic endpoint for resolving data-options@ directives used in blueprints.
     * Returns the array result of calling a whitelisted static PHP method.
     * Client should cache responses — these are effectively static data.
     */
    public function resolveData(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $query = $request->getQueryParams();
        $callable = $query['callable'] ?? null;

        if (!$callable || !is_string($callable)) {
            throw new ValidationException(['callable' => ['The callable query parameter is required.']]);
        }

        $callable = ltrim($callable, '\\');

        // Validate against whitelist
        $allowed = false;
        foreach (self::RESOLVE_ALLOWED_NAMESPACES as $ns) {
            if (str_starts_with($callable, $ns)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new ValidationException(['callable' => ['Callable is not in the allowed namespace list.']]);
        }

        // Ensure Pages subsystem for Page-related callables
        if (str_contains($callable, 'Page')) {
            $this->ensurePagesEnabled();
        }

        if (!str_contains($callable, '::')) {
            throw new ValidationException(['callable' => ['Callable must be in Class::method format.']]);
        }

        [$class, $method] = explode('::', $callable, 2);
        $class = '\\' . $class;

        if (!class_exists($class) || !method_exists($class, $method)) {
            throw new NotFoundException("Callable '{$callable}' not found.");
        }

        // For pageTypes(), pass the type arg so it returns standard or modular
        if ($method === 'pageTypes') {
            $type = $query['type'] ?? 'standard';
            $result = $class::$method($type);
        } else {
            $result = $class::$method();
        }

        if (!is_array($result)) {
            return ApiResponse::create([]);
        }

        // Normalize to [{value, label}] format for select options
        $normalized = [];
        foreach ($result as $key => $label) {
            $normalized[] = [
                'value' => (string) $key,
                'label' => is_string($label) ? $label : (string) $key,
            ];
        }

        return ApiResponse::create($normalized);
    }

    /**
     * GET /blueprints/pages - List available page blueprints (templates).
     */
    public function pageTypes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');
        $this->ensurePagesEnabled();

        $types = Pages::types();
        $result = [];

        foreach ($types as $type => $label) {
            $result[] = [
                'type' => $type,
                'label' => is_string($label) ? $label : $type,
            ];
        }

        return ApiResponse::create($result);
    }

    /**
     * GET /blueprints/pages/{template} - Get resolved blueprint for a page template.
     */
    public function pageBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $template = $this->getRouteParam($request, 'template');

        $blueprint = $this->loadPageBlueprint($template);

        if (!$blueprint) {
            throw new NotFoundException("Blueprint for template '{$template}' not found.");
        }

        $data = $this->serializeBlueprint($blueprint, $template);

        // Fire event to allow plugins to modify the serialized blueprint fields
        // (e.g., editor-pro overrides editor/markdown field types). The
        // explicit `context` discriminator lets listeners gate behavior to a
        // specific blueprint family (e.g. ai-translate annotates only pages).
        $event = new Event([
            'context' => 'page',
            'fields' => $data['fields'],
            'template' => $template,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/plugins/{plugin} - Get resolved blueprint for a plugin.
     */
    public function pluginBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        $pluginName = $this->getRouteParam($request, 'plugin');
        $pluginPath = $this->grav['locator']->findResource("plugin://{$pluginName}");

        if (!$pluginPath || !file_exists($pluginPath . '/blueprints.yaml')) {
            throw new NotFoundException("Blueprint for plugin '{$pluginName}' not found.");
        }

        $blueprint = new Blueprint($pluginPath . '/blueprints.yaml');
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, $pluginName);

        // Fire event to allow plugins to modify serialized fields
        $event = new Event([
            'context' => 'plugin',
            'fields' => $data['fields'],
            'plugin' => $pluginName,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/themes/{theme} - Get resolved blueprint for a theme.
     */
    public function themeBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        $themeName = $this->getRouteParam($request, 'theme');
        $themesPath = $this->grav['locator']->findResource('themes://');
        $themePath = $themesPath . '/' . $themeName;

        if (!is_dir($themePath) || !file_exists($themePath . '/blueprints.yaml')) {
            throw new NotFoundException("Blueprint for theme '{$themeName}' not found.");
        }

        $blueprint = new Blueprint($themePath . '/blueprints.yaml');
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, $themeName);

        // Fire event so plugins can extend / annotate theme blueprints, with
        // an explicit `context` discriminator so listeners (e.g. ai-translate)
        // can scope behavior to a specific blueprint family.
        $event = new Event([
            'context' => 'theme',
            'fields' => $data['fields'],
            'theme' => $themeName,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/users - Get the user account blueprint.
     */
    public function userBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        // The user blueprint is just the form schema, not user data — every
        // authenticated user needs it to render their own profile form, even
        // those without api.users.read.
        $this->requirePermission($request, 'api.access');

        $blueprintPath = $this->grav['locator']->findResource('blueprints://user/account.yaml');

        if (!$blueprintPath) {
            $blueprintPath = $this->grav['locator']->findResource('system://blueprints/user/account.yaml');
        }

        if (!$blueprintPath) {
            throw new NotFoundException('User account blueprint not found.');
        }

        $blueprint = new Blueprint($blueprintPath);
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, 'account');

        // Fire event so plugins can extend the user blueprint (e.g. admin2
        // injects the account-state toggle, since core's account.yaml has
        // no field for it).
        $event = new Event([
            'context' => 'account',
            'fields' => $data['fields'],
            'template' => 'account',
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/users/permissions - Get all registered permission actions.
     */
    public function permissionsBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.read');

        /** @var \Grav\Framework\Acl\Permissions $permissions */
        $permissions = $this->grav['permissions'];

        $sections = [];
        foreach ($permissions as $name => $action) {
            $sections[] = $this->serializePermissionAction($action, $name);
        }

        return ApiResponse::create($sections);
    }

    /**
     * Recursively serialize a permission action and its children.
     */
    private function serializePermissionAction(object $action, string $name): array
    {
        $rawLabel = $action->label ?? $name;
        $label = $this->translateLabel($rawLabel);

        $data = [
            'name' => $name,
            'label' => $label,
        ];

        // Check for child actions
        $children = [];
        if ($action instanceof \IteratorAggregate || $action instanceof \Traversable) {
            foreach ($action as $child) {
                // Use $child->name which has the full dotted path (e.g. "admin.login")
                $children[] = $this->serializePermissionAction($child, $child->name ?? $name);
            }
        }

        if ($children) {
            $data['children'] = $children;
        }

        return $data;
    }

    /**
     * Translate a blueprint / permission label string.
     *
     * Lookup order, ICU-first:
     *   1. `ICU.<key>` — admin2's authoritative namespace (Grav 2 convention).
     *   2. `<key>` — flat lookup, for legacy plugins that ship PLUGIN_ADMIN.*
     *      under the Grav 1 convention (form, login, flex-objects, etc.).
     *   3. `PLUGIN_API.<last-segment>` — last-resort api-plugin namespace.
     *   4. Humanizer over the key itself.
     *
     * ICU is checked first by design: admin classic's plugin folder may still
     * be present in dev installs (disabled, mid-migration) and Grav core's
     * `flattenByLang()` reads every plugin's lang files regardless of enabled
     * state. Without the ICU-first order, admin classic's flat values would
     * shadow admin2's ICU ports — a per-key drift that's hard to spot. Putting
     * ICU first makes admin2 the source of truth for any key it ships, and
     * lets the flat lookup serve as a transition fallback for keys admin2
     * hasn't ported (or that legitimate 3rd-party plugins ship under
     * PLUGIN_ADMIN.* for shared-vocabulary labels).
     */
    protected function translateLabel(string $label): string
    {
        $lang = $this->grav['language'];

        // If it looks like a language key (e.g. PLUGIN_ADMIN.ACCESS_SITE), try to translate
        if (str_contains($label, '.') && strtoupper($label) === $label) {
            $icuKey = 'ICU.' . $label;
            $icuTranslated = $lang->translate($icuKey);
            if ($icuTranslated !== $icuKey) {
                return $icuTranslated;
            }

            // Skip the flat lookup if the only source for this key is a disabled
            // plugin — a disabled plugin shouldn't influence what admin2 renders.
            $currentLang = $lang->getLanguage() ?: 'en';
            if (!$this->disabledLangIndex()->isDisabledOnly($label, $currentLang)) {
                $translated = $lang->translate($label);
                if ($translated !== $label) {
                    return $translated;
                }
            }

            // Try API plugin namespace as fallback
            $key = substr($label, strrpos($label, '.') + 1);
            $apiTranslated = $lang->translate('PLUGIN_API.' . $key);
            if ($apiTranslated !== 'PLUGIN_API.' . $key) {
                return $apiTranslated;
            }
        }

        // If the label is still a raw key, derive a human-readable name from the permission name
        if (strtoupper($label) === $label && str_contains($label, '_')) {
            // PLUGIN_ADMIN.ACCESS_ADMIN_CONFIGURATION -> Configuration
            $parts = explode('.', $label);
            $last = end($parts);
            // Remove ACCESS_ prefix
            $last = preg_replace('/^ACCESS_(?:ADMIN_|SITE_)?/', '', $last);
            return ucwords(strtolower(str_replace('_', ' ', $last)));
        }

        return $label;
    }

    /**
     * GET /blueprints/plugins/{plugin}/pages/{pageId} - Get custom page blueprint for a plugin.
     */
    public function pluginPageBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        $plugin = $this->getRouteParam($request, 'plugin');
        $pageId = $this->getRouteParam($request, 'pageId');

        $pluginPath = $this->grav['locator']->findResource("plugin://{$plugin}");

        if (!$pluginPath) {
            throw new NotFoundException("Plugin '{$plugin}' not found.");
        }

        $blueprintFile = $pluginPath . '/admin/blueprints/' . basename($pageId) . '.yaml';

        // Fallback: when the dedicated admin/blueprints/{pageId}.yaml is missing
        // and the page id matches the plugin slug, treat the plugin's main
        // blueprints.yaml as the page blueprint. Lets plugins whose admin-next
        // settings page is just the existing plugin form skip maintaining a
        // duplicate YAML — algolia-pro keeps its dedicated page blueprint, but
        // simpler plugins (git-sync) reuse the one they already have.
        if (!file_exists($blueprintFile) && $pageId === $plugin && file_exists($pluginPath . '/blueprints.yaml')) {
            $blueprintFile = $pluginPath . '/blueprints.yaml';
        }

        if (!file_exists($blueprintFile)) {
            throw new NotFoundException("Page blueprint '{$pageId}' not found for plugin '{$plugin}'.");
        }

        $blueprint = new Blueprint($blueprintFile);
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, $pageId);

        // Fire event so plugins (notably flex-objects) can extend plugin
        // page blueprints — e.g. inject the shared Flex configure tabs
        // (Caching) when the owning plugin manages a Flex directory.
        $event = new Event([
            'context'  => 'plugin-page',
            'fields'   => $data['fields'],
            'plugin'   => $plugin,
            'page_id'  => $pageId,
            'user'     => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/config/{scope} - Get blueprint for system/site config.
     */
    public function configBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        $scope = $this->getRouteParam($request, 'scope');
        $validScopes = ['system', 'site', 'media', 'security', 'scheduler', 'backups'];

        if (!in_array($scope, $validScopes, true)) {
            throw new NotFoundException("Config blueprint scope '{$scope}' not found. Valid: " . implode(', ', $validScopes));
        }

        // Use the blueprints:// stream to find config blueprints so that
        // plugin overrides (e.g., admin's media.yaml) are resolved correctly.
        $realPath = $this->grav['locator']->findResource("blueprints://config/{$scope}.yaml");

        if (!$realPath) {
            // Fallback to system blueprints directly
            $realPath = $this->grav['locator']->findResource("system://blueprints/config/{$scope}.yaml");
        }

        if (!$realPath) {
            throw new NotFoundException("Config blueprint for '{$scope}' not found.");
        }

        $blueprint = new Blueprint($realPath);
        $blueprint->load();

        return ApiResponse::create($this->serializeBlueprint($blueprint, $scope));
    }

    /**
     * Load a fully-resolved page blueprint via Grav core's standard pipeline.
     *
     * Delegates to Pages::blueprints() (= Blueprints::loadFile() → Blueprint::load()->init())
     * — the same path admin-classic uses. This honors every BlueprintForm
     * directive (replace@, unset@, replace-<prop>@, ordering@, import@ with
     * inline insertion, @extends with context, config-default@, etc.), and
     * fires onBlueprintCreated so plugins can extend the result.
     *
     * Earlier versions hand-rolled YAML merging here to dodge a perceived
     * memory-exhaustion risk in the full pipeline. In practice Grav core
     * runs this code on every page edit in admin-classic without trouble,
     * and the hand-rolled path silently dropped most BlueprintForm directives
     * (see grav-plugin-admin2#3).
     */
    private function loadPageBlueprint(string $template): ?Blueprint
    {
        $this->ensurePagesEnabled();

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        try {
            return $pages->blueprints($template);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Ensure the Pages subsystem is initialized.
     * Many data-options@ directives reference Pages:: methods that need this.
     */
    protected function ensurePagesEnabled(): void
    {
        if ($this->pagesEnabled) {
            return;
        }
        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }
        $this->pagesEnabled = true;
    }

    protected bool $pagesEnabled = false;

    /**
     * Resolve a data-*@ directive by calling the referenced PHP callable.
     * Supports format: '\Grav\Common\Utils::timezones' or ['method', 'args']
     */
    protected function resolveDataDirective(mixed $directive): ?array
    {
        try {
            $callable = is_array($directive) ? ($directive[0] ?? null) : $directive;
            if (!is_string($callable)) {
                return null;
            }

            $callable = ltrim($callable, '\\');

            // Parse Class::method format
            if (str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);
                $class = '\\' . $class;

                // Ensure Pages subsystem is available for Page-related callables
                if (str_contains($class, 'Page')) {
                    $this->ensurePagesEnabled();
                }

                if (class_exists($class) && method_exists($class, $method)) {
                    // pageTypes() needs a type arg. Use the current serialization
                    // context (modular if we're serializing a `modular/*` blueprint,
                    // standard otherwise) so the template selector gets the right
                    // list baked in.
                    if ($method === 'pageTypes') {
                        $result = $class::$method($this->pageTypeContext);
                    } else {
                        $result = $class::$method();
                    }
                    return is_array($result) ? $result : null;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Serialize a Blueprint object into a JSON-friendly structure.
     */
    /**
     * Page-type context for the current serialization pass. Read by
     * resolveDataDirective() when expanding `Pages::pageTypes` so a modular
     * template's blueprint gets the modular template list instead of the
     * default 'standard' list.
     */
    private string $pageTypeContext = 'standard';

    protected function serializeBlueprint(Blueprint $blueprint, string $name): array
    {
        $form = $blueprint->form();
        $fields = $blueprint->fields();

        // Modular page templates live under `modular/` (e.g. `modular/hero`).
        // Track this so Pages::pageTypes resolves to the modular list for the
        // template field instead of the standard list.
        $this->pageTypeContext = str_starts_with($name, 'modular/') ? 'modular' : 'standard';

        return [
            'name' => $name,
            'title' => $form['title'] ?? $blueprint->get('name') ?? $name,
            'type' => $blueprint->get('type') ?? null,
            'child_type' => $blueprint->get('child_type') ?? null,
            'validation' => $form['validation'] ?? 'loose',
            'fields' => $this->serializeFields($fields),
        ];
    }

    /**
     * Recursively serialize blueprint fields into a structure
     * suitable for client-side form rendering.
     */
    protected function serializeFields(array $fields, string $prefix = ''): array
    {
        $result = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = $field['type'] ?? null;
            $fieldPath = $prefix ? "{$prefix}.{$name}" : $name;

            $serialized = [
                'name' => $fieldPath,
                'type' => $type ?? 'text',
            ];

            // Copy standard properties
            $props = [
                'label', 'help', 'placeholder', 'default', 'description', 'content',
                'size', 'classes', 'id', 'style', 'title', 'text',
                'disabled', 'readonly', 'toggleable', 'highlight',
                'minlength', 'maxlength', 'min', 'max', 'step',
                'rows', 'cols', 'multiple', 'yaml',
                'markdown', 'prepend', 'append', 'underline',
                'options', 'selectize', 'value_only', 'create',
                'destination', 'accept',
                'use', 'key', 'controls', 'collapsed',
                'show_all', 'show_modular', 'show_root', 'show_slug',
                'placeholder_key', 'placeholder_value', 'value_type',
                'btnLabel', 'placement', 'sortby', 'sortby_dir',
                'sort', 'collapsible', 'min_height', 'selectunique',
                'condition', 'wrapper_classes',
                'provider', 'translate',
                'page_field', 'page_template', 'success_msg', 'error_msg',
                // pagemediaselect / filepicker
                'preview_images', 'preview_image', 'on_demand', 'folder', 'filter',
                'self', 'display', 'resize', 'media_picker_field',
                // colorpicker — opt out of the alpha slider with `alpha: false`.
                'alpha',
            ];

            foreach ($props as $prop) {
                if (isset($field[$prop])) {
                    $serialized[$prop] = $field[$prop];
                }
            }

            // Translate string properties that may contain language keys
            foreach (['label', 'title', 'description', 'help', 'placeholder', 'text', 'content', 'success_msg', 'error_msg'] as $textProp) {
                if (isset($serialized[$textProp]) && is_string($serialized[$textProp])) {
                    $serialized[$textProp] = $this->translateLabel($serialized[$textProp]);
                }
            }

            // Translate option labels
            if (isset($serialized['options']) && is_array($serialized['options'])) {
                foreach ($serialized['options'] as $optKey => $optLabel) {
                    if (is_string($optLabel)) {
                        $serialized['options'][$optKey] = $this->translateLabel($optLabel);
                    }
                }
            }

            // Resolve data-options@ directives (dynamic options from PHP callables).
            // Grav core's Blueprint::dynamicData() may have already populated
            // $serialized['options'] using a stateless call; we replace it with
            // our resolution because we have page-type context for pageTypes.
            if (isset($field['data-options@'])) {
                $directive = $field['data-options@'];
                $resolved = $this->resolveDataDirective($directive);
                if ($resolved !== null && count($resolved) > 0) {
                    $serialized['options'] = $resolved;
                } else {
                    // Include the directive reference so client can resolve via /data/resolve
                    $serialized['data_options'] = is_string($directive) ? $directive : ($directive[0] ?? null);
                }
            }

            // Convert options from {key: label} object to [{value, label}] array
            // to preserve insertion order (JS re-sorts numeric object keys)
            if (isset($serialized['options']) && is_array($serialized['options'])) {
                $ordered = [];
                foreach ($serialized['options'] as $optKey => $optLabel) {
                    $ordered[] = ['value' => (string) $optKey, 'label' => $optLabel];
                }
                $serialized['options'] = $ordered;
            }

            // Validation rules
            if (isset($field['validate']) && is_array($field['validate'])) {
                $serialized['validate'] = $field['validate'];
            }

            // Handle nested fields (structural containers)
            if (isset($field['fields']) && is_array($field['fields'])) {
                // For layout containers, don't add prefix (fields bind to their own names)
                $layoutTypes = ['tabs', 'tab', 'section', 'fieldset', 'columns', 'column', 'page-exists', 'elements', 'element'];
                $childPrefix = in_array($type, $layoutTypes, true) ? $prefix : $fieldPath;

                $serialized['fields'] = $this->serializeFields($field['fields'], $childPrefix);
            }

            $result[] = $serialized;
        }

        return $result;
    }
}
