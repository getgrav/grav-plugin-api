<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * Markdown editor toolbar buttons — lets plugins contribute buttons to the
 * admin-next default (CodeMirror) markdown editor toolbar, the same way
 * Editor Pro exposes `registerEditorProPlugin` for its own toolbar.
 *
 * Plugins listen for `onApiMarkdownEditorButtons` and append button defs.
 * A button either opens a plugin modal (which builds and inserts content
 * itself, e.g. via the `grav:editor:insert-content` window event) or carries
 * an `insert` payload the editor applies directly.
 *
 * Button format:
 *   [
 *     'id'        => 'youtube',                 // unique identifier
 *     'plugin'    => 'youtube',                 // owning plugin slug
 *     'label'     => 'YouTube Video',           // tooltip / aria-label
 *     'icon'      => '<svg ...>',               // inline SVG markup, or an FA class
 *     'modal'     => [                          // optional — open a plugin modal
 *         'component' => 'youtube-insert',      //   admin-next/modals/{component}.js
 *         'title'     => 'Insert YouTube Video',
 *         'size'      => 'md',
 *     ],
 *     'insert'    => [                          // optional — insert directly (no modal)
 *         'content' => '---',
 *         'mode'    => 'insert-at-cursor',      //   insert-at-cursor | append | replace
 *     ],
 *     'authorize' => 'api.pages.write',         // optional — string or any-of array
 *   ]
 *
 * `authorize` follows the same string-or-array semantics as the menubar and
 * sidebar APIs. Buttons without `authorize` are visible to every authenticated
 * user.
 */
class EditorButtonsController extends AbstractApiController
{
    /**
     * GET /editor/toolbar-buttons — Collect markdown editor toolbar buttons
     * from plugins, filtered by the current user's permissions.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $event = new Event(['buttons' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiMarkdownEditorButtons', $event);

        $isSuperAdmin = $this->isSuperAdmin($user);
        $filtered = [];
        foreach ($event['buttons'] as $button) {
            if (!$this->userPassesAuthorize($user, $button['authorize'] ?? null, $isSuperAdmin)) {
                continue;
            }
            // Strip the authorize field — it's a server-side annotation, not client data
            unset($button['authorize']);
            $filtered[] = $button;
        }

        return ApiResponse::create($filtered);
    }
}
