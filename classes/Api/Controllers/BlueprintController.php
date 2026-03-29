<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Blueprints;
use Grav\Common\Page\Pages;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class BlueprintController extends AbstractApiController
{
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

        return ApiResponse::create($this->serializeBlueprint($blueprint, $template));
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

        return ApiResponse::create($this->serializeBlueprint($blueprint, $pluginName));
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

        return ApiResponse::create($this->serializeBlueprint($blueprint, $themeName));
    }

    /**
     * GET /blueprints/config/{scope} - Get blueprint for system/site config.
     */
    public function configBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        $scope = $this->getRouteParam($request, 'scope');
        $validScopes = ['system', 'site', 'media', 'security'];

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
     * Load page blueprint by reading YAML files directly.
     * Resolves extends@ by manually merging parent blueprints
     * to avoid the heavy Blueprint::load() resolution chain that
     * causes memory exhaustion with deep page blueprint inheritance.
     */
    private function loadPageBlueprint(string $template): ?Blueprint
    {
        $merged = $this->resolvePageBlueprintYaml($template, 0);

        // Fallback to 'default' if no template-specific blueprint exists
        if (!$merged && $template !== 'default') {
            $merged = $this->resolvePageBlueprintYaml('default', 0);
        }

        if (!$merged) {
            return null;
        }

        // Create a Blueprint from the resolved data
        $blueprint = new Blueprint();
        $blueprint->embed('', $merged);

        return $blueprint;
    }

    /**
     * Recursively resolve page blueprint YAML with extends@ support.
     *
     * @param string $template Template name to resolve
     * @param int $depth Recursion depth guard
     * @param bool $systemOnly If true, only search system blueprints (for extends resolution)
     */
    private function resolvePageBlueprintYaml(string $template, int $depth = 0, bool $systemOnly = false): ?array
    {
        if ($depth > 5) {
            return null;
        }

        $data = null;

        // Try theme blueprints first (unless resolving an extends, which should use system)
        if (!$systemOnly) {
            $themePath = $this->grav['locator']->findResource("theme://blueprints/{$template}.yaml");
            if ($themePath && file_exists($themePath)) {
                $data = Yaml::parse(file_get_contents($themePath));
            }
        }

        // Try system page blueprints
        if (!$data) {
            $systemPath = $this->grav['locator']->findResource("system://blueprints/pages/{$template}.yaml");
            if ($systemPath && file_exists($systemPath)) {
                $data = Yaml::parse(file_get_contents($systemPath));
            }
        }

        if (!$data || !is_array($data)) {
            return null;
        }

        // Resolve extends@ — always resolve from system to avoid self-reference
        if (isset($data['extends@'])) {
            $parentTemplate = $data['extends@'];
            unset($data['extends@']);

            $parent = $this->resolvePageBlueprintYaml($parentTemplate, $depth + 1, true);
            if ($parent) {
                $data = $this->deepMerge($parent, $data);
            }
        }

        // Resolve import@ directives in fields
        $data = $this->resolveImports($data);

        return $data;
    }

    /**
     * Resolve import@ directives within blueprint data.
     */
    private function resolveImports(array $data): array
    {
        array_walk_recursive($data, function (&$value, $key) use (&$data) {
            // handled at field level below
        });

        // Walk through form fields looking for import@ directives
        if (isset($data['form']['fields'])) {
            $data['form']['fields'] = $this->resolveFieldImports($data['form']['fields']);
        }

        return $data;
    }

    /**
     * Recursively resolve import@ in field arrays.
     */
    private function resolveFieldImports(array $fields): array
    {
        foreach ($fields as $key => &$field) {
            if (!is_array($field)) continue;

            // Handle import@
            if (isset($field['import@'])) {
                $import = $field['import@'];
                $type = is_array($import) ? ($import['type'] ?? null) : $import;
                $context = is_array($import) ? ($import['context'] ?? 'blueprints://pages') : 'blueprints://pages';

                if ($type) {
                    $importPath = $this->grav['locator']->findResource("{$context}/{$type}.yaml");
                    if ($importPath && file_exists($importPath)) {
                        $imported = Yaml::parse(file_get_contents($importPath));
                        if (is_array($imported) && isset($imported['form']['fields'])) {
                            $field = $this->deepMerge($field, ['fields' => $imported['form']['fields']]);
                        }
                    }
                }
                unset($field['import@']);
            }

            // Recurse into nested fields
            if (isset($field['fields']) && is_array($field['fields'])) {
                $field['fields'] = $this->resolveFieldImports($field['fields']);
            }
        }

        return $fields;
    }

    /**
     * Deep merge arrays, with $override taking precedence.
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Ensure the Pages subsystem is initialized.
     * Many data-options@ directives reference Pages:: methods that need this.
     */
    private function ensurePagesEnabled(): void
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

    private bool $pagesEnabled = false;

    /**
     * Resolve a data-*@ directive by calling the referenced PHP callable.
     * Supports format: '\Grav\Common\Utils::timezones' or ['method', 'args']
     */
    private function resolveDataDirective(mixed $directive): ?array
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
                    // pageTypes() needs a type arg; default to standard for blueprint serialization
                    if ($method === 'pageTypes') {
                        $result = $class::$method('standard');
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
    private function serializeBlueprint(Blueprint $blueprint, string $name): array
    {
        $form = $blueprint->form();
        $fields = $blueprint->fields();

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
    private function serializeFields(array $fields, string $prefix = ''): array
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
                'label', 'help', 'placeholder', 'default', 'description',
                'size', 'classes', 'id', 'style', 'title', 'text',
                'disabled', 'readonly', 'toggleable', 'highlight',
                'minlength', 'maxlength', 'min', 'max', 'step',
                'rows', 'cols', 'multiple', 'yaml',
                'markdown', 'prepend', 'append', 'underline',
                'options', 'selectize', 'value_only',
                'destination', 'accept',
                'use', 'key', 'controls', 'collapsed',
                'show_all', 'show_modular', 'show_root', 'show_slug',
                'placeholder_key', 'placeholder_value', 'value_type',
                'btnLabel', 'placement', 'sortby', 'sortby_dir',
                'sort', 'collapsible', 'min_height', 'selectunique',
            ];

            foreach ($props as $prop) {
                if (isset($field[$prop])) {
                    $serialized[$prop] = $field[$prop];
                }
            }

            // Resolve data-options@ directives (dynamic options from PHP callables)
            if (isset($field['data-options@'])) {
                $directive = $field['data-options@'];
                $resolved = $this->resolveDataDirective($directive);
                if ($resolved !== null && count($resolved) > 0) {
                    $existing = $serialized['options'] ?? [];
                    $serialized['options'] = is_array($existing) ? $existing + $resolved : $resolved;
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
                $layoutTypes = ['tabs', 'tab', 'section', 'fieldset', 'columns', 'column'];
                $childPrefix = in_array($type, $layoutTypes, true) ? $prefix : $fieldPath;

                $serialized['fields'] = $this->serializeFields($field['fields'], $childPrefix);
            }

            $result[] = $serialized;
        }

        return $result;
    }
}
