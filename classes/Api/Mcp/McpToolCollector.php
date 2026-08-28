<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Mcp;

use Closure;
use stdClass;

/**
 * Collects the MCP tool definitions contributed by plugins, validates them and
 * normalizes them into the payload `GET /mcp/tools` serves.
 *
 * A plugin describes its tools declaratively in `mcp.yaml` (read by
 * {@see McpManifestLoader}) or adds them at runtime through `onApiMcpTools`:
 *
 *   public function onApiMcpTools(Event $event): void {
 *       $event['tools']->add('kahunacart', [
 *           'name'        => 'sync_stripe',
 *           'description' => 'Re-sync products and prices with Stripe.',
 *           'method'      => 'POST',
 *           'path'        => '/kahunacart/providers/stripe/sync',
 *           'permission'  => 'kahunacart.settings',
 *       ]);
 *   }
 *
 * Both routes go through the same validation. An entry that breaks a rule is
 * dropped and the reason is recorded in {@see warnings()}, so one bad tool never
 * costs a plugin the rest of its manifest and never takes the endpoint down.
 *
 * The final tool name is `{prefix}_{name}`, with the prefix defaulting to the
 * plugin slug (dashes become underscores). The first plugin to claim a name
 * keeps it; a later duplicate is dropped with a warning.
 */
class McpToolCollector
{
    /** HTTP methods a tool may use. Multipart routes are out of scope for version 1. */
    private const METHODS = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

    /** Annotation keys a tool may set, each a plain boolean. */
    private const ANNOTATIONS = ['readOnly', 'destructive', 'idempotent'];

    /** Types a schema node may declare. */
    private const TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    /** `format` values the converter understands. Advisory only: they inform the description. */
    private const FORMATS = ['date', 'date-time', 'email', 'uri'];

    /**
     * Schema keywords the manifest may use. Anything outside this list is
     * rejected, because grav-mcp turns the schema into a zod schema at load
     * time and only understands these.
     */
    private const SCHEMA_KEYWORDS = [
        'type', 'description', 'default', 'enum', 'nullable',
        'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'format',
        'items', 'properties', 'required', 'additionalProperties',
    ];

    /** Longest acceptable final tool name, matching the MCP tool-name limit. */
    private const MAX_NAME_LENGTH = 64;

    /** @var array<string, array<string, mixed>> Accepted tools, keyed by final name. */
    private array $tools = [];

    /** @var array<int, string> Human-readable reasons entries were dropped. */
    private array $warnings = [];

    /** @var array<string, string> Name prefix per plugin slug. */
    private array $prefixes = [];

    /** @var array<int, string> Slugs of plugins that contributed, in the order they did. */
    private array $contributors = [];

    /**
     * @param Closure|null $metadata Resolves a plugin slug to its display name
     *   and version for {@see plugins()}, as `['name' => ?string, 'version' =>
     *   ?string]`. Called once per contributing plugin, so a collector used on
     *   its own (in tests, say) needs no filesystem at all.
     */
    public function __construct(private readonly ?Closure $metadata = null) {}

    /**
     * Note that a plugin is contributing, and optionally give its tools a name
     * prefix other than the slug default.
     *
     * The loader calls this as soon as a manifest parses, so a plugin whose
     * every tool was rejected still appears under `plugins` with a count of
     * zero rather than vanishing as if it shipped nothing.
     */
    public function registerPlugin(string $plugin, ?string $prefix = null): void
    {
        $plugin = trim($plugin);
        if ($plugin === '') {
            return;
        }

        if (!in_array($plugin, $this->contributors, true)) {
            $this->contributors[] = $plugin;
        }

        if (is_string($prefix) && trim($prefix) !== '') {
            $this->prefixes[$plugin] = trim($prefix);
        }
    }

    /**
     * Validate one tool definition and keep it if every rule holds.
     *
     * @param string $plugin The owning plugin's slug.
     * @param array<string, mixed> $tool The definition, in manifest form.
     */
    public function add(string $plugin, array $tool): void
    {
        $plugin = trim($plugin);
        if ($plugin === '') {
            $this->warnings[] = "tool skipped: no plugin slug was given for it";
            return;
        }

        $this->registerPlugin($plugin);

        $name = $tool['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $this->reject($plugin, null, "'name' is required");
            return;
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $this->reject($plugin, $name, "'name' must match ^[a-z][a-z0-9_]*$");
            return;
        }

        $finalName = $this->prefixFor($plugin) . '_' . $name;
        if (preg_match('/^[a-z][a-z0-9_]*$/', $finalName) !== 1) {
            $this->reject($plugin, $finalName, "the prefixed name must match ^[a-z][a-z0-9_]*$");
            return;
        }
        if (strlen($finalName) > self::MAX_NAME_LENGTH) {
            $this->reject($plugin, $finalName, sprintf(
                'the prefixed name is %d characters, over the %d character limit',
                strlen($finalName),
                self::MAX_NAME_LENGTH,
            ));
            return;
        }
        if (isset($this->tools[$finalName])) {
            $this->reject($plugin, $finalName, sprintf(
                "duplicate tool name, already contributed by '%s'",
                $this->tools[$finalName]['plugin'],
            ));
            return;
        }

        $description = $tool['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            $this->reject($plugin, $finalName, "'description' is required");
            return;
        }

        $method = $tool['method'] ?? null;
        if (!is_string($method) || !in_array(strtoupper($method), self::METHODS, true)) {
            $this->reject($plugin, $finalName, sprintf(
                "'method' must be one of %s",
                implode(', ', self::METHODS),
            ));
            return;
        }
        $method = strtoupper($method);

        $path = $tool['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/')) {
            $this->reject($plugin, $finalName, "'path' is required and must start with /");
            return;
        }

        $permission = $tool['permission'] ?? null;
        if ($permission !== null && (!is_string($permission) || trim($permission) === '')) {
            $this->reject($plugin, $finalName, "'permission' must be a string");
            return;
        }
        $permission = is_string($permission) ? trim($permission) : null;

        $title = $tool['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            $this->reject($plugin, $finalName, "'title' must be a string");
            return;
        }

        $annotations = $this->annotations($method, $tool['annotations'] ?? null, $error);
        if ($annotations === null) {
            $this->reject($plugin, $finalName, $error);
            return;
        }

        $input = $tool['input'] ?? null;
        if ($input !== null && !is_array($input)) {
            $this->reject($plugin, $finalName, "'input' must be a JSON Schema object");
            return;
        }
        if (is_array($input) && ($input['type'] ?? 'object') !== 'object') {
            $this->reject($plugin, $finalName, "'input' must be of type object");
            return;
        }

        $schema = $this->schema($input ?? ['type' => 'object', 'properties' => []], '', $error);
        if ($schema === null) {
            $this->reject($plugin, $finalName, $error);
            return;
        }

        $pathParams = $this->pathParams($path, $error);
        if ($pathParams === null) {
            $this->reject($plugin, $finalName, $error);
            return;
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($pathParams as $param) {
            if (!array_key_exists($param, $properties)) {
                $this->reject($plugin, $finalName, sprintf(
                    "path parameter '%s' is not declared in input.properties",
                    $param,
                ));
                return;
            }
        }

        // A path parameter is part of the URL, so the tool cannot be called
        // without it, whatever the manifest's own `required` list says.
        $required = array_values(array_unique(array_merge(
            array_map(strval(...), (array) ($schema['required'] ?? [])),
            $pathParams,
        )));
        if ($required !== []) {
            $schema['required'] = $required;
        } else {
            unset($schema['required']);
        }

        $query = $this->query($tool['query'] ?? null, $properties, $pathParams, $error);
        if ($query === null) {
            $this->reject($plugin, $finalName, $error);
            return;
        }

        $this->tools[$finalName] = [
            'name' => $finalName,
            'plugin' => $plugin,
            'title' => $title,
            'description' => trim($description),
            'method' => $method,
            'path' => $path,
            'permission' => $permission,
            'annotations' => $annotations,
            'input_schema' => $this->encodeSchema($schema),
            'path_params' => $pathParams,
            'query' => $query,
        ];
    }

    /**
     * Record a warning that is not about a single tool, such as a manifest that
     * could not be parsed at all.
     */
    public function warn(string $plugin, string $message): void
    {
        $this->warnings[] = $plugin . ': ' . $message;
    }

    /**
     * Every accepted tool, in the order it was contributed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array
    {
        return array_values($this->tools);
    }

    /**
     * Why each rejected entry was rejected. Safe to show to any authenticated
     * caller: it names plugins, tools and schema keywords, nothing else.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The contributing plugins with their tool counts.
     *
     * @return array<int, array{slug: string, name: string|null, version: string|null, tools: int}>
     */
    public function plugins(): array
    {
        $counts = [];
        foreach ($this->tools as $tool) {
            $counts[$tool['plugin']] = ($counts[$tool['plugin']] ?? 0) + 1;
        }

        $plugins = [];
        foreach ($this->contributors as $slug) {
            $meta = $this->metadata !== null ? ($this->metadata)($slug) : [];
            $plugins[] = [
                'slug' => $slug,
                'name' => is_string($meta['name'] ?? null) ? $meta['name'] : null,
                'version' => isset($meta['version']) ? (string) $meta['version'] : null,
                'tools' => $counts[$slug] ?? 0,
            ];
        }

        return $plugins;
    }

    /**
     * The name prefix in force for a plugin: whatever its manifest asked for,
     * otherwise the slug with dashes turned into underscores so the result can
     * pass the tool-name pattern.
     */
    private function prefixFor(string $plugin): string
    {
        return $this->prefixes[$plugin] ?? str_replace('-', '_', $plugin);
    }

    /**
     * Apply the per-method annotation defaults, then let the manifest override
     * individual keys. A key the manifest does not mention keeps its default.
     *
     * @return array<string, bool>|null Null when the annotations are malformed.
     */
    private function annotations(string $method, mixed $given, ?string &$error): ?array
    {
        $defaults = match ($method) {
            'GET' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
            'DELETE' => ['readOnly' => false, 'destructive' => true, 'idempotent' => true],
            'PUT', 'PATCH' => ['readOnly' => false, 'destructive' => false, 'idempotent' => true],
            default => ['readOnly' => false, 'destructive' => false, 'idempotent' => false],
        };

        if ($given === null) {
            return $defaults;
        }
        if (!is_array($given)) {
            $error = "'annotations' must be a map of booleans";
            return null;
        }

        foreach ($given as $key => $value) {
            if (!is_string($key) || !in_array($key, self::ANNOTATIONS, true)) {
                $error = sprintf(
                    "unknown annotation '%s' (expected one of %s)",
                    is_string($key) ? $key : (string) $key,
                    implode(', ', self::ANNOTATIONS),
                );
                return null;
            }
            if (!is_bool($value)) {
                $error = sprintf("annotation '%s' must be true or false", $key);
                return null;
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * The `{name}` placeholders in a path, in the order they appear.
     *
     * Only bare names are accepted. A FastRoute regex constraint (`{id:\d+}`)
     * would leave grav-mcp with a placeholder it cannot substitute, so it is
     * rejected rather than silently mangled.
     *
     * @return array<int, string>|null Null when a placeholder is malformed.
     */
    private function pathParams(string $path, ?string &$error): ?array
    {
        preg_match_all('/\{([^}]*)\}/', $path, $matches);

        $params = [];
        foreach ($matches[1] as $raw) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $raw) !== 1) {
                $error = sprintf("path placeholder '{%s}' must be a bare parameter name", $raw);
                return null;
            }
            if (in_array($raw, $params, true)) {
                $error = sprintf("path placeholder '{%s}' appears more than once", $raw);
                return null;
            }
            $params[] = $raw;
        }

        return $params;
    }

    /**
     * Validate the `query` list: the properties a write method sends as
     * query-string parameters rather than in the JSON body.
     *
     * Ignored for `GET`, which sends every non-path property as a query
     * parameter, but still validated so a mistake is reported rather than kept.
     *
     * @param array<string, mixed> $properties
     * @param array<int, string> $pathParams
     * @return array<int, string>|null Null when the list is malformed.
     */
    private function query(mixed $given, array $properties, array $pathParams, ?string &$error): ?array
    {
        if ($given === null) {
            return [];
        }
        if (!is_array($given) || array_is_list($given) === false) {
            $error = "'query' must be a list of property names";
            return null;
        }

        $names = [];
        foreach ($given as $name) {
            if (!is_string($name) || $name === '') {
                $error = "'query' must be a list of property names";
                return null;
            }
            if (!array_key_exists($name, $properties)) {
                $error = sprintf("query parameter '%s' is not declared in input.properties", $name);
                return null;
            }
            if (in_array($name, $pathParams, true)) {
                $error = sprintf("'%s' is a path parameter and cannot also be sent as a query parameter", $name);
                return null;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Walk a schema node and everything under it, rejecting any keyword outside
     * the supported subset.
     *
     * @param array<string, mixed> $node
     * @param string $path Dotted location of this node inside `input`, for the warning text.
     * @return array<string, mixed>|null Null when the node breaks a rule.
     */
    private function schema(array $node, string $path, ?string &$error): ?array
    {
        $where = $path === '' ? 'input' : $path;

        foreach (array_keys($node) as $keyword) {
            if (!is_string($keyword) || !in_array($keyword, self::SCHEMA_KEYWORDS, true)) {
                $error = sprintf(
                    "unsupported schema keyword '%s' at %s",
                    is_string($keyword) ? $keyword : (string) $keyword,
                    $where,
                );
                return null;
            }
        }

        $type = $node['type'] ?? null;
        if ($type !== null && (!is_string($type) || !in_array($type, self::TYPES, true))) {
            $error = sprintf("unsupported type at %s (expected one of %s)", $where, implode(', ', self::TYPES));
            return null;
        }

        if (array_key_exists('enum', $node)) {
            if (!is_array($node['enum']) || $node['enum'] === [] || !array_is_list($node['enum'])) {
                $error = sprintf("'enum' at %s must be a non-empty list", $where);
                return null;
            }
            foreach ($node['enum'] as $value) {
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    $error = sprintf("'enum' at %s may only hold strings and numbers", $where);
                    return null;
                }
            }
        }

        if (array_key_exists('nullable', $node) && $node['nullable'] !== true) {
            $error = sprintf("'nullable' at %s may only be true", $where);
            return null;
        }

        foreach (['minimum', 'maximum'] as $keyword) {
            if (array_key_exists($keyword, $node) && !is_int($node[$keyword]) && !is_float($node[$keyword])) {
                $error = sprintf("'%s' at %s must be a number", $keyword, $where);
                return null;
            }
        }

        foreach (['minLength', 'maxLength'] as $keyword) {
            if (array_key_exists($keyword, $node) && !is_int($node[$keyword])) {
                $error = sprintf("'%s' at %s must be an integer", $keyword, $where);
                return null;
            }
        }

        if (array_key_exists('pattern', $node) && !is_string($node['pattern'])) {
            $error = sprintf("'pattern' at %s must be a string", $where);
            return null;
        }

        if (array_key_exists('description', $node) && !is_string($node['description'])) {
            $error = sprintf("'description' at %s must be a string", $where);
            return null;
        }

        if (array_key_exists('format', $node) && !in_array($node['format'], self::FORMATS, true)) {
            $error = sprintf("unsupported format at %s (expected one of %s)", $where, implode(', ', self::FORMATS));
            return null;
        }

        if (array_key_exists('additionalProperties', $node) && !is_bool($node['additionalProperties'])) {
            $error = sprintf("'additionalProperties' at %s must be true or false", $where);
            return null;
        }

        if (array_key_exists('required', $node)) {
            if (!is_array($node['required']) || !array_is_list($node['required'])) {
                $error = sprintf("'required' at %s must be a list of property names", $where);
                return null;
            }
            foreach ($node['required'] as $name) {
                if (!is_string($name)) {
                    $error = sprintf("'required' at %s must be a list of property names", $where);
                    return null;
                }
            }
        }

        if (array_key_exists('items', $node)) {
            if (!is_array($node['items']) || array_is_list($node['items'])) {
                // A list here is a tuple definition, which the converter does
                // not support: an array has one item type or it has none.
                $error = sprintf("'items' at %s must be a single schema, not a tuple", $where);
                return null;
            }
            $items = $this->schema($node['items'], $path === '' ? 'items' : $path . '.items', $error);
            if ($items === null) {
                return null;
            }
            $node['items'] = $items;
        }

        if (array_key_exists('properties', $node)) {
            if (!is_array($node['properties'])) {
                $error = sprintf("'properties' at %s must be a map", $where);
                return null;
            }
            $properties = [];
            foreach ($node['properties'] as $name => $child) {
                $childPath = ($path === '' ? '' : $path . '.') . 'properties.' . $name;
                if (!is_array($child)) {
                    $error = sprintf("the definition at %s must be a schema object", $childPath);
                    return null;
                }
                $child = $this->schema($child, $childPath, $error);
                if ($child === null) {
                    return null;
                }
                $properties[(string) $name] = $child;
            }
            $node['properties'] = $properties;
        } elseif (($node['type'] ?? null) === 'object' && !array_key_exists('additionalProperties', $node)) {
            // An object with no declared properties is a free-form map.
            $node['additionalProperties'] = true;
        }

        return $node;
    }

    /**
     * Make the schema safe to JSON-encode: an empty property map has to go out
     * as `{}` rather than PHP's `[]`, or every client reads it as an array.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function encodeSchema(array $schema): array
    {
        if (array_key_exists('properties', $schema)) {
            foreach ($schema['properties'] as $name => $child) {
                $schema['properties'][$name] = $this->encodeSchema($child);
            }
            if ($schema['properties'] === []) {
                $schema['properties'] = new stdClass();
            }
        }
        if (array_key_exists('items', $schema) && is_array($schema['items'])) {
            $schema['items'] = $this->encodeSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * Drop an entry and say why.
     */
    private function reject(string $plugin, ?string $name, string $reason): void
    {
        $this->warnings[] = $name === null
            ? sprintf('%s: tool skipped: %s', $plugin, $reason)
            : sprintf("%s: tool '%s' skipped: %s", $plugin, $name, $reason);
    }
}
