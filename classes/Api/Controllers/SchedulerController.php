<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Scheduler\Scheduler;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SchedulerController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.scheduler.read';
    private const PERMISSION_WRITE = 'api.scheduler.write';

    /**
     * GET /scheduler/jobs - List all registered scheduler jobs with status.
     */
    public function jobs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        /** @var Scheduler $scheduler */
        $scheduler = $this->grav['scheduler'];
        $allJobs = $scheduler->getAllJobs();
        $states = $scheduler->getJobStates()->content();

        $data = [];
        foreach ($allJobs as $job) {
            $id = $job->getId();
            $command = $job->getCommand();
            $state = $states[$id] ?? null;

            $data[] = [
                'id' => $id,
                'command' => is_string($command) ? $command : '(closure)',
                'expression' => $job->getAt(),
                'enabled' => $job->getEnabled(),
                'status' => $state['state'] ?? 'pending',
                'last_run' => isset($state['last-run']) ? date('c', $state['last-run']) : null,
                'error' => $state['error'] ?? null,
            ];
        }

        return ApiResponse::create($data);
    }

    /**
     * GET /scheduler/status - Get scheduler cron status.
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        /** @var Scheduler $scheduler */
        $scheduler = $this->grav['scheduler'];

        $crontabStatus = $scheduler->isCrontabSetup();
        $statusMap = [0 => 'not_installed', 1 => 'installed', 2 => 'error'];

        $data = [
            'crontab_status' => $statusMap[$crontabStatus] ?? 'unknown',
            'cron_command' => $scheduler->getCronCommand(),
            'scheduler_command' => $scheduler->getSchedulerCommand(),
            'whoami' => $scheduler->whoami(),
        ];

        return ApiResponse::create($data);
    }

    /**
     * GET /scheduler/history - Job execution history (paginated).
     */
    public function history(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $pagination = $this->getPagination($request);

        /** @var Scheduler $scheduler */
        $scheduler = $this->grav['scheduler'];
        $states = $scheduler->getJobStates()->content();

        // Convert states to array sorted by last-run desc
        $history = [];
        foreach ($states as $jobId => $state) {
            $history[] = [
                'job_id' => $jobId,
                'status' => $state['state'] ?? 'unknown',
                'last_run' => isset($state['last-run']) ? date('c', $state['last-run']) : null,
                'last_run_timestamp' => $state['last-run'] ?? 0,
                'error' => $state['error'] ?? null,
            ];
        }

        // Sort by last_run descending
        usort($history, fn($a, $b) => ($b['last_run_timestamp'] ?? 0) <=> ($a['last_run_timestamp'] ?? 0));

        // Remove the timestamp helper field
        $history = array_map(function ($item) {
            unset($item['last_run_timestamp']);
            return $item;
        }, $history);

        $total = count($history);
        $slice = array_slice($history, $pagination['offset'], $pagination['limit']);
        $baseUrl = $this->getApiBaseUrl() . '/scheduler/history';

        return ApiResponse::paginated(
            data: $slice,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $baseUrl,
        );
    }

    /**
     * POST /scheduler/run - Trigger scheduler run manually.
     */
    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        /** @var Scheduler $scheduler */
        $scheduler = $this->grav['scheduler'];

        $body = $this->getRequestBody($request);
        $force = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $scheduler->run(null, $force);

        // Collect results
        $states = $scheduler->getJobStates()->content();

        return ApiResponse::create([
            'message' => 'Scheduler run completed.',
            'forced' => $force,
            'job_states' => $states,
        ]);
    }

    /**
     * GET /reports - Generate system reports.
     */
    public function reports(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $reports = [];

        // PHP info
        $reports['php'] = [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'extensions' => get_loaded_extensions(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        // Grav info
        $reports['grav'] = [
            'version' => GRAV_VERSION,
            'php_version' => PHP_VERSION,
        ];

        // Disk usage
        $rootPath = GRAV_ROOT;
        $reports['disk'] = [
            'free_space' => disk_free_space($rootPath),
            'total_space' => disk_total_space($rootPath),
        ];

        // Plugin status
        $plugins = $this->grav['plugins']->all();
        $enabledPlugins = 0;
        $disabledPlugins = 0;
        foreach ($plugins as $name => $plugin) {
            if ($this->grav['config']->get("plugins.{$name}.enabled", false)) {
                $enabledPlugins++;
            } else {
                $disabledPlugins++;
            }
        }

        $reports['plugins'] = [
            'total' => count($plugins),
            'enabled' => $enabledPlugins,
            'disabled' => $disabledPlugins,
        ];

        // Cache status
        $cacheDriver = $this->grav['config']->get('system.cache.driver', 'auto');
        $cacheEnabled = $this->grav['config']->get('system.cache.enabled', true);
        $reports['cache'] = [
            'enabled' => $cacheEnabled,
            'driver' => $cacheDriver,
        ];

        return ApiResponse::create($reports);
    }
}
