<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Helpers\YamlLinter;
use Grav\Common\Page\Pages;
use Grav\Common\Security;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

class ReportsController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.reports.read';

    /**
     * GET /reports - Generate plugin-extensible reports.
     *
     * Built-in reports: Security Check, YAML Linter.
     * Plugins can add reports via the onApiGenerateReports event.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $reports = [];

        // Built-in: Grav Security Check (XSS scan)
        $reports[] = $this->securityReport();

        // Built-in: YAML Linter
        $reports[] = $this->yamlLinterReport();

        // Fire event for plugins to add their own reports
        $event = new Event(['reports' => $reports]);
        $this->grav->fireEvent('onApiGenerateReports', $event);
        $reports = $event['reports'];

        return ApiResponse::create($reports);
    }

    /**
     * Scan all pages for potential XSS vulnerabilities.
     */
    private function securityReport(): array
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $pages->enablePages();

        $result = Security::detectXssFromPages($pages, true);

        $items = [];
        foreach ($result as $route => $fields) {
            foreach ($fields as $field) {
                $items[] = [
                    'route' => $route,
                    'field' => $field,
                ];
            }
        }

        $issueCount = count($items);

        return [
            'id' => 'security-check',
            'title' => 'Grav Security Check',
            'provider' => 'core',
            'component' => null,
            'status' => $issueCount === 0 ? 'success' : 'warning',
            'message' => $issueCount === 0
                ? 'Security Scan complete: No issues found.'
                : "Security Scan complete: {$issueCount} potential XSS issue" . ($issueCount > 1 ? 's' : '') . ' found...',
            'items' => $items,
        ];
    }

    /**
     * Lint all YAML files for syntax errors.
     */
    private function yamlLinterReport(): array
    {
        $result = YamlLinter::lint();

        $items = [];
        foreach ($result as $file => $error) {
            $items[] = [
                'file' => $file,
                'error' => $error,
            ];
        }

        $errorCount = count($items);

        return [
            'id' => 'yaml-linter',
            'title' => 'Grav Yaml Linter',
            'provider' => 'core',
            'component' => null,
            'status' => $errorCount === 0 ? 'success' : 'error',
            'message' => $errorCount === 0
                ? 'YAML Linting: No errors found.'
                : "YAML Linting: {$errorCount} error" . ($errorCount > 1 ? 's' : '') . ' found.',
            'items' => $items,
        ];
    }
}
