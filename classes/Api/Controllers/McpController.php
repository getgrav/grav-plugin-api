<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Mcp\McpManifestLoader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * MCP tool manifests: the union of the tools every enabled plugin describes,
 * served to an MCP server so it can offer them to a model.
 *
 * A plugin declares its tools in `mcp.yaml` at its root, or adds them in code
 * through `onApiMcpTools`. See {@see \Grav\Plugin\Api\Mcp\McpToolCollector} for
 * the rules an entry has to satisfy, and the README section "MCP tool
 * manifests" for the manifest format itself.
 */
class McpController extends AbstractApiController
{
    /**
     * GET /mcp/tools: every tool this caller is allowed to call.
     *
     * A tool that names a permission the caller does not hold is left out, so
     * the model is never offered a call it will only be refused. Tools that name
     * no permission are always included, and a super admin sees everything.
     */
    public function tools(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $loader = new McpManifestLoader($this->grav, $this->config);
        $collector = $loader->load();
        $fingerprint = $loader->fingerprint();
        $etag = '"' . $fingerprint . '"';

        if ($this->etagMatches($request->getHeaderLine('If-None-Match'), $etag)) {
            return new Response(304, ['ETag' => $etag], '');
        }

        $user = $this->getUser($request);

        $tools = [];
        $counts = [];
        foreach ($collector->tools() as $tool) {
            if (!$this->userPassesAuthorize($user, $tool['permission'], $request)) {
                continue;
            }
            $tools[] = $tool;
            $counts[$tool['plugin']] = ($counts[$tool['plugin']] ?? 0) + 1;
        }

        // `plugins[].tools` counts what THIS caller can see, not what the
        // manifest declared, so the listing and the tool array agree.
        $plugins = [];
        foreach ($collector->plugins() as $plugin) {
            $plugin['tools'] = $counts[$plugin['slug']] ?? 0;
            $plugins[] = $plugin;
        }

        return $this->respondWithEtag([
            'tools' => $tools,
            'plugins' => $plugins,
            'warnings' => $collector->warnings(),
            'fingerprint' => $fingerprint,
        ], 200, [], $fingerprint);
    }
}
