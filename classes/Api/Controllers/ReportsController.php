<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Helpers\YamlLinter;
use Grav\Common\Page\Pages;
use Grav\Common\Security;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\YamlFile;

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

        // Built-in: Twig in Content (gate/sandbox state, leaking pages, blocks)
        $reports[] = $this->twigContentReport();

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
     * Map a sandbox rule (the suffix of a `sandbox_*` event type) to the
     * security.twig_sandbox config key that gates it, and whether that key is a
     * flat list (tags/filters/functions) or a class-keyed map (methods/properties).
     *
     * @var array<string,array{key:string,kind:string}>
     */
    private const SANDBOX_ALLOWLIST_KEYS = [
        'tag'      => ['key' => 'allowed_tags', 'kind' => 'list'],
        'filter'   => ['key' => 'allowed_filters', 'kind' => 'list'],
        'function' => ['key' => 'allowed_functions', 'kind' => 'list'],
        'method'   => ['key' => 'allowed_methods', 'kind' => 'map'],
        'property' => ['key' => 'allowed_properties', 'kind' => 'map'],
    ];

    /**
     * Report on the "Twig in Content" pipeline: whether the master gate and the
     * sandbox are on, which pages would leak raw {{ }} / {% %} to visitors, and
     * the recent blocked tokens (with the core remediation hint) from the
     * Phase 1 diagnostics ring buffer. Each sandbox-block row carries an
     * `allowlist` descriptor the UI turns into a one-click "Add to allowlist".
     */
    private function twigContentReport(): array
    {
        $config = $this->grav['config'];
        $gate        = (bool) $config->get('security.twig_content.process_enabled', false);
        $sandbox     = (bool) $config->get('security.twig_sandbox.enabled', true);
        $xssScan     = (bool) $config->get('security.twig_content.xss_scan_output', true);
        $editorWide  = (bool) $config->get('security.twig_content.editor_enabled', false);

        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $pages->enablePages();

        $leaks  = Security::detectTwigLeaksFromPages($pages);
        $events = Security::recentTwigContentEvents();

        $items = [];

        // Pages whose raw Twig markers will render verbatim to visitors.
        foreach ($leaks as $leak) {
            $items[] = [
                'kind'      => 'leak',
                'route'     => $leak['route'],
                'reason'    => $leak['reason'],      // gate_off | page_off
                'requested' => $leak['requested'],
            ];
        }

        // Recent blocked/blanked tokens, newest first, with remediation hint.
        foreach ($events as $event) {
            $items[] = [
                'kind'      => 'event',
                'type'      => $event['type'],
                'route'     => $event['route'],
                'token'     => $event['token'],
                'class'     => $event['class'],
                'hint'      => $event['hint'],
                'timestamp' => $event['timestamp'],
                'allowlist' => $this->allowlistDescriptor($event),
            ];
        }

        $leakCount  = count($leaks);
        $eventCount = count($events);

        $status = ($leakCount > 0 || $eventCount > 0) ? 'warning' : 'success';
        if ($leakCount === 0 && $eventCount === 0) {
            $message = $gate
                ? 'Twig in Content is enabled. No leaking pages or recent blocks detected.'
                : 'Twig in Content is disabled. No pages are leaking raw Twig.';
        } else {
            $parts = [];
            if ($leakCount > 0) {
                $parts[] = "{$leakCount} page" . ($leakCount > 1 ? 's' : '') . ' leaking raw Twig';
            }
            if ($eventCount > 0) {
                $parts[] = "{$eventCount} recent block" . ($eventCount > 1 ? 's' : '');
            }
            $message = 'Twig in Content: ' . implode(', ', $parts) . '.';
        }

        return [
            'id'        => 'twig-content',
            'title'     => 'Twig in Content',
            'provider'  => 'core',
            'component' => null,
            'status'    => $status,
            'message'   => $message,
            'meta'      => [
                'gate'           => $gate,
                'sandbox'        => $sandbox,
                'xss_scan'       => $xssScan,
                'editor_enabled' => $editorWide,
                'leak_count'     => $leakCount,
                'event_count'    => $eventCount,
            ],
            'items'     => $items,
        ];
    }

    /**
     * Build the "Add to allowlist" descriptor for a sandbox-block event, or null
     * for events with no allowlist remedy (gate_blocked, xss_blanked).
     *
     * @param array{type:string,token:string,class:string} $event
     * @return array{rule:string,key:string,kind:string,token:string,class:string}|null
     */
    private function allowlistDescriptor(array $event): ?array
    {
        if (!str_starts_with($event['type'], 'sandbox_')) {
            return null;
        }
        $rule = substr($event['type'], strlen('sandbox_'));
        $target = self::SANDBOX_ALLOWLIST_KEYS[$rule] ?? null;
        if ($target === null || $event['token'] === '') {
            return null;
        }
        // method/property rules need the owning class to target the right row.
        if ($target['kind'] === 'map' && $event['class'] === '') {
            return null;
        }

        return [
            'rule'  => $rule,
            'key'   => $target['key'],
            'kind'  => $target['kind'],
            'token' => $event['token'],
            'class' => $event['class'],
        ];
    }

    /**
     * POST /reports/twig-content/allowlist
     *
     * Append a single blocked token to the matching security.twig_sandbox
     * allowlist. Writes the FULL effective list back to user/config/security.yaml
     * because Grav replaces (never deep-merges) YAML lists on config merge — a
     * partial override would wipe the shipped defaults. Restricted to API super
     * users, mirroring the security-scope write rule in ConfigController.
     *
     * Body: { rule: tag|filter|function|method|property, token: string, class?: string }
     */
    public function allowlistAdd(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.write');
        if (!$this->isSuperAdmin($this->getUser($request))) {
            throw new ForbiddenException('The Twig sandbox allowlist can only be modified by an API super user.');
        }

        $body  = $this->getRequestBody($request);
        $rule  = is_string($body['rule'] ?? null) ? trim($body['rule']) : '';
        $token = is_string($body['token'] ?? null) ? trim($body['token']) : '';
        $class = is_string($body['class'] ?? null) ? trim($body['class']) : '';

        $target = self::SANDBOX_ALLOWLIST_KEYS[$rule] ?? null;
        if ($target === null) {
            throw new ValidationException("Unknown sandbox rule '{$rule}'. Expected one of: tag, filter, function, method, property.");
        }
        if ($token === '') {
            throw new ValidationException('A non-empty token is required.');
        }
        if ($target['kind'] === 'map' && $class === '') {
            throw new ValidationException("Rule '{$rule}' requires the owning class.");
        }

        $configPath = 'security.twig_sandbox.' . $target['key'];
        $current = (array) $this->grav['config']->get($configPath, []);

        $updated = $target['kind'] === 'list'
            ? $this->appendToList($current, $token)
            : $this->appendToMethodMap($current, $class, $token);

        $this->persistSecurityKey($target['key'], $updated);

        return ApiResponse::ok([
            'rule'  => $rule,
            'key'   => $target['key'],
            'value' => $updated,
        ]);
    }

    /**
     * DELETE /reports/twig-content/events — clear the diagnostics ring buffer
     * once the operator has dealt with the flagged blocks.
     */
    public function clearTwigEvents(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.reports.read');
        $cleared = Security::clearTwigContentEvents();

        return ApiResponse::ok(['cleared' => $cleared]);
    }

    /**
     * Append a token to a flat allowlist (tags/filters/functions), de-duped
     * case-insensitively, preserving existing order.
     *
     * @param array<int,mixed> $list
     * @return list<string>
     */
    private function appendToList(array $list, string $token): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $value) {
            if (!is_string($value)) {
                continue;
            }
            $out[] = $value;
            $seen[strtolower($value)] = true;
        }
        if (!isset($seen[strtolower($token)])) {
            $out[] = $token;
        }

        return $out;
    }

    /**
     * Append a method/property to a class-keyed allowlist. Each row is
     * {class, methods} where `methods` is a comma-separated string (the
     * security.yaml shape). Appends to the matching row's list, de-duped, or
     * adds a new row when the class isn't present yet.
     *
     * @param array<int,mixed> $rows
     * @return list<array{class:string,methods:string}>
     */
    private function appendToMethodMap(array $rows, string $class, string $token): array
    {
        $out = [];
        $matched = false;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['class'])) {
                continue;
            }
            $rowClass = (string) $row['class'];
            $methods = $this->splitMethods((string) ($row['methods'] ?? ''));
            if (strcasecmp($rowClass, $class) === 0) {
                $matched = true;
                if (!$this->hasCaseInsensitive($methods, $token)) {
                    $methods[] = $token;
                }
            }
            $out[] = ['class' => $rowClass, 'methods' => implode(', ', $methods)];
        }
        if (!$matched) {
            $out[] = ['class' => $class, 'methods' => $token];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function splitMethods(string $methods): array
    {
        $parts = array_map('trim', explode(',', $methods));

        return array_values(array_filter($parts, static fn($m) => $m !== ''));
    }

    /**
     * @param list<string> $haystack
     */
    private function hasCaseInsensitive(array $haystack, string $needle): bool
    {
        foreach ($haystack as $item) {
            if (strcasecmp($item, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist a single twig_sandbox key to user/config/security.yaml, merging
     * with any existing user overrides, then update the in-memory config and
     * clear caches. Mirrors ConfigController's save side effects (onApiConfigUpdated
     * + cache clear) without routing through the full env-aware update flow —
     * the sandbox allowlist is a global, base-config concern.
     */
    private function persistSecurityKey(string $key, mixed $value): void
    {
        $file = $this->grav['locator']->findResource('user://config/security.yaml', true, true);
        if (!is_string($file)) {
            throw new \RuntimeException('Unable to resolve user/config/security.yaml for writing.');
        }

        $yaml = YamlFile::instance($file);
        $data = (array) $yaml->content();
        $data['twig_sandbox'] ??= [];
        if (!is_array($data['twig_sandbox'])) {
            $data['twig_sandbox'] = [];
        }
        $data['twig_sandbox'][$key] = $value;
        $yaml->save($data);
        $yaml->free();

        // Update the live config so subsequent reads in this request agree.
        $this->grav['config']->set('security.twig_sandbox.' . $key, $value);
        $this->grav['cache']->clearCache('standard');

        $this->fireEvent('onApiConfigUpdated', ['scope' => 'security', 'data' => $this->grav['config']->get('security')]);
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
