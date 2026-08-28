<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Mcp;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\ApiRouter;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

/**
 * Reads every enabled plugin's `mcp.yaml`, feeds it to an {@see McpToolCollector},
 * then gives plugins that build tools from runtime data their turn through the
 * `onApiMcpTools` event.
 *
 * File manifests are read first and the event fires afterwards, so a plugin can
 * see its own declared tools already registered and a duplicate name added in
 * code loses to the one on disk.
 *
 * Nothing here throws on bad input. A manifest that will not parse, or that
 * carries a version this plugin does not know, becomes one warning naming the
 * plugin and the remaining plugins are read as usual.
 */
class McpManifestLoader
{
    /** The only manifest format that exists. */
    private const MANIFEST_VERSION = 1;

    /** Identity of the manifest set, computed while loading. */
    private ?string $fingerprint = null;

    /** @var array<string, array{name: string|null, version: string|null}> Cached plugin metadata. */
    private array $metadata = [];

    public function __construct(
        private readonly Grav $grav,
        private readonly Config $config,
    ) {}

    /**
     * Collect every plugin's tools.
     */
    public function load(): McpToolCollector
    {
        $collector = new McpToolCollector(fn(string $slug): array => $this->pluginMetadata($slug));

        // The fingerprint is keyed on the same enabled-plugin set the route
        // cache uses, plus each manifest's mtime: installing, enabling,
        // disabling or editing a plugin all move it, so a client can tell
        // whether anything changed without diffing the tool list.
        $parts = [];

        foreach (ApiRouter::enabledPluginSlugs($this->config) as $slug) {
            $parts[] = $slug;

            $file = $this->manifestPath($slug);
            if ($file === null) {
                continue;
            }
            $parts[] = $slug . '@' . (@filemtime($file) ?: 0);

            $this->read($collector, $slug, $file);
        }

        $this->fingerprint = substr(hash('sha256', implode('|', $parts)), 0, 16);

        $this->grav->fireEvent('onApiMcpTools', new Event(['tools' => $collector]));

        return $collector;
    }

    /**
     * Identity of the manifest set from the last {@see load()}, used as the
     * response's ETag. Tools added through `onApiMcpTools` have no file behind
     * them and so do not move it.
     */
    public function fingerprint(): string
    {
        return $this->fingerprint ?? '';
    }

    /**
     * Parse one plugin's manifest and hand its tools to the collector.
     */
    private function read(McpToolCollector $collector, string $slug, string $file): void
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            $collector->warn($slug, 'mcp.yaml could not be read');
            return;
        }

        try {
            $data = Yaml::parse($raw);
        } catch (Throwable $e) {
            $collector->warn($slug, 'mcp.yaml could not be parsed: ' . $e->getMessage());
            return;
        }

        if (!is_array($data)) {
            $collector->warn($slug, 'mcp.yaml is empty or is not a mapping');
            return;
        }

        $version = $data['version'] ?? null;
        if (!is_int($version) && !is_string($version)) {
            $collector->warn($slug, "mcp.yaml is missing 'version: 1'");
            return;
        }
        if ((int) $version !== self::MANIFEST_VERSION) {
            $collector->warn($slug, sprintf(
                'mcp.yaml declares manifest version %s, which this version of the API plugin does not read',
                (string) $version,
            ));
            return;
        }

        $prefix = $data['prefix'] ?? null;
        $collector->registerPlugin($slug, is_string($prefix) ? $prefix : null);

        $tools = $data['tools'] ?? [];
        if (!is_array($tools)) {
            $collector->warn($slug, "mcp.yaml's 'tools' must be a list");
            return;
        }

        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                $collector->warn($slug, 'mcp.yaml holds an entry under `tools` that is not a mapping');
                continue;
            }
            $collector->add($slug, $tool);
        }
    }

    /**
     * Locate a plugin's manifest, or null when it ships none.
     */
    private function manifestPath(string $slug): ?string
    {
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/', $slug) !== 1) {
            return null;
        }

        $path = $this->grav['locator']->findResource("plugins://{$slug}/mcp.yaml", true);

        return is_string($path) && is_file($path) ? $path : null;
    }

    /**
     * A plugin's display name and version, read from its blueprint.
     *
     * @return array{name: string|null, version: string|null}
     */
    private function pluginMetadata(string $slug): array
    {
        if (isset($this->metadata[$slug])) {
            return $this->metadata[$slug];
        }

        $meta = ['name' => null, 'version' => null];
        $path = $this->grav['locator']->findResource("plugins://{$slug}/blueprints.yaml", true);

        if (is_string($path) && is_file($path)) {
            try {
                $data = Yaml::parse((string) file_get_contents($path));
                if (is_array($data)) {
                    $meta['name'] = is_string($data['name'] ?? null) ? $data['name'] : null;
                    $meta['version'] = isset($data['version']) ? (string) $data['version'] : null;
                }
            } catch (Throwable) {
                // A plugin whose own blueprint will not parse still gets to
                // contribute tools; it just goes unnamed in the listing.
            }
        }

        return $this->metadata[$slug] = $meta;
    }
}
