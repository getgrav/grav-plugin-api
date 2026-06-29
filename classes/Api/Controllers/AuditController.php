<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Audit\AuditStore;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Read-only API for the admin audit trail. Every route is super-admin-only;
 * the audit log is a privileged forensic view, distinct from general admin
 * access (a non-super editor whose actions are recorded must not be able to
 * read the log of everyone else's).
 *
 * The trail records API-layer activity. Admin-Next routes everything through
 * the API, so it captures the whole admin surface plus external API-key
 * clients; classic-admin actions that bypass the API are not included.
 */
class AuditController extends AbstractApiController
{
    private const FILTERS = ['event', 'actor', 'target_type', 'severity', 'from', 'to', 'q'];

    /**
     * GET /audit/status: feature state for the admin UI's tab-visibility check.
     * Reports enabled/coverage/retention plus whether the SQLite backend is even
     * available, so the front-end can hide the tab cleanly when it isn't.
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');

        $available = AuditStore::available();
        $enabled = $available && (bool) $this->config->get('plugins.api.audit.enabled', false);

        $count = null;
        if ($enabled) {
            try {
                $count = (new AuditStore())->count();
            } catch (\Throwable) {
                $count = null;
            }
        }

        return ApiResponse::ok([
            'enabled'   => $enabled,
            'available' => $available,
            'coverage'  => (string) $this->config->get('plugins.api.audit.coverage', 'standard'),
            'retention' => [
                'days'     => (int) $this->config->get('plugins.api.audit.retention_days', 90),
                'max_rows' => (int) $this->config->get('plugins.api.audit.retention_max_rows', 100000),
            ],
            'total'     => $count,
        ]);
    }

    /**
     * GET /audit/events: paginated, filterable event list.
     */
    public function events(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $this->assertEnabled();

        $pagination = $this->getPagination($request, 50);
        $filters = $this->collectFilters($request);

        $result = (new AuditStore())->query($filters, $pagination['limit'], $pagination['offset']);

        return ApiResponse::paginated(
            $result['rows'],
            $result['total'],
            $pagination['page'],
            $pagination['per_page'],
            $this->getApiBaseUrl() . '/audit/events',
        );
    }

    /**
     * GET /audit/facets: distinct event types + actors for the filter UI.
     */
    public function facets(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $this->assertEnabled();

        return ApiResponse::ok((new AuditStore())->facets());
    }

    /**
     * GET /audit/export?format=csv|json: download the (filtered) log.
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $this->assertEnabled();

        $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'csv'));
        $filters = $this->collectFilters($request);
        $rows = (new AuditStore())->queryAll($filters);

        if ($format === 'json') {
            return new Response(
                200,
                [
                    'Content-Type' => 'application/json',
                    'Content-Disposition' => 'attachment; filename="audit-log.json"',
                    'Cache-Control' => 'no-store',
                ],
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        }

        return new Response(
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="audit-log.csv"',
                'Cache-Control' => 'no-store',
            ],
            $this->toCsv($rows),
        );
    }

    // ---------------------------------------------------------------------

    /**
     * @param ServerRequestInterface $request
     * @return array<string,mixed>
     */
    private function collectFilters(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $filters = [];
        foreach (self::FILTERS as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $filters[$key] = $query[$key];
            }
        }
        return $filters;
    }

    private function assertEnabled(): void
    {
        if (!AuditStore::available()) {
            throw new \Grav\Plugin\Api\Exceptions\ApiException(
                503,
                'Service Unavailable',
                'The audit trail requires the SQLite PHP extension, which is not installed.'
            );
        }
        if (!$this->config->get('plugins.api.audit.enabled', false)) {
            throw new \Grav\Plugin\Api\Exceptions\ApiException(
                404,
                'Not Found',
                'The audit trail is not enabled.'
            );
        }
    }

    /**
     * Render rows as CSV. Values are CSV-injection-safe: any cell that begins
     * with a formula trigger (= + - @, or tab/CR) is prefixed with a single
     * quote so spreadsheet apps don't execute it.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function toCsv(array $rows): string
    {
        $headers = [
            'id', 'ts', 'event', 'severity', 'actor_id', 'actor_name',
            'actor_roles', 'auth_method', 'ip', 'user_agent',
            'target_type', 'target_id', 'status', 'context',
        ];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $col) {
                $value = $row[$col] ?? '';
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES);
                }
                $line[] = $this->csvSafe((string) $value);
            }
            fputcsv($out, $line);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? '' : $csv;
    }

    private function csvSafe(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
