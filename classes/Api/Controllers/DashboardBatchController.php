<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /admin-next/dashboard — everything the Admin Next dashboard loads when
 * it opens, in one request instead of nine. Its stats come with
 * GET /admin-next/boot, which the sidebar's badges need on every screen.
 *
 * Each part is its own endpoint's handler, called in-process with this
 * request, so `data.<part>` is exactly the `data` of that endpoint and the
 * same permission check applies. A part the caller may not read, or one that
 * fails, is left out of `data` and reported in `errors` with the status and
 * title its endpoint would have sent.
 *
 * Each request costs a full Grav bootstrap, and a host with a few PHP workers
 * on HTTP/1.1 runs the dashboard's requests a handful at a time. They also
 * count against the API rate limit one by one: nine here, where a few reloads
 * of the dashboard used to be enough to reach it.
 */
class DashboardBatchController extends AbstractApiController
{
    use CollectsParts;

    /**
     * Part name => [controller, handler, query]. The handler is the one the
     * part's own endpoint routes to; the query is what that endpoint is sent.
     */
    private const PARTS = [
        'system_info' => [SystemController::class, 'info', []],             // GET /system/info
        'notifications' => [DashboardController::class, 'notifications', []], // GET /dashboard/notifications
        'popularity' => [DashboardController::class, 'popularity', []],     // GET /dashboard/popularity
        'feed' => [DashboardController::class, 'feed', []],                 // GET /dashboard/feed
        'backups' => [SystemController::class, 'backups', []],              // GET /system/backups
        'updates' => [GpmController::class, 'updates', []],                 // GET /gpm/updates
        'reports' => [SchedulerController::class, 'systemInfo', []],        // GET /systeminfo
        'widgets' => [DashboardWidgetController::class, 'widgets', []],     // GET /dashboard/widgets
        'recent_pages' => [PagesController::class, 'index', [               // GET /pages, newest first
            'sort' => 'modified',
            'order' => 'desc',
            'per_page' => '8',
            'fields' => 'summary',
        ]],
    ];

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        // `recent_pages=editable` lists only pages the caller can edit, as
        // `GET /pages?editable=true` does (admin2's pages.only_editable).
        $editableOnly = ($request->getQueryParams()['recent_pages'] ?? '') === 'editable';

        $data = [];
        $errors = [];

        foreach (self::PARTS as $part => [$class, $method, $query]) {
            if ($part === 'recent_pages' && $editableOnly) {
                $query['editable'] = 'true';
            }
            $this->collect($part, $data, $errors, fn () => $this->endpointData($class, $method, $request, $query));
        }

        return ApiResponse::parts($data, $errors);
    }
}
