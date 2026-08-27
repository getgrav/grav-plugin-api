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
     * Register system jobs on the scheduler.
     *
     * Core registers the Backups listener and fires onSchedulerInitialized itself, once, so
     * plugins get to add their jobs (cache-purge, cache-clear, backups and the rest). Firing
     * the event from here as well would register every one of them twice.
     */
    private function initializeSchedulerJobs(Scheduler $scheduler): void
    {
        $scheduler->initializeJobs();
    }

    /**
     * GET /scheduler/jobs - List all registered scheduler jobs with status.
     */
    public function jobs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        /** @var Scheduler $scheduler */
        $scheduler = $this->grav['scheduler'];

        $this->initializeSchedulerJobs($scheduler);

        $allJobs = $scheduler->getAllJobs();
        $states = (array) $scheduler->getJobStates()->content();
        $now = new \DateTime('now');

        $data = [];
        foreach ($allJobs as $job) {
            $id = $job->getId();
            $command = $job->getCommand();
            $state = $states[$id] ?? null;

            // What the next scheduled run is, and whether the job already missed the last one.
            // Together these are what tells somebody without a cron entry which jobs a manual
            // run is actually going to pick up.
            $nextRun = null;
            $expression = $job->getCronExpression();
            if ($expression) {
                try {
                    $nextRun = $expression->getNextRunDate($now)->format('c');
                } catch (\Throwable $e) {
                    $nextRun = null;
                }
            }

            $overdue = false;
            if ($job->getEnabled() && method_exists($scheduler, 'isOverdue')) {
                try {
                    $overdue = $scheduler->isOverdue($job, $now, $states);
                } catch (\Throwable $e) {
                    $overdue = false;
                }
            }

            $data[] = [
                'id' => $id,
                'command' => is_string($command) ? $command : '(closure)',
                'expression' => $job->getAt(),
                'enabled' => $job->getEnabled(),
                'status' => $state['state'] ?? 'pending',
                'last_run' => isset($state['last-run']) ? date('c', $state['last-run']) : null,
                'last_run_trigger' => $state['trigger'] ?? null,
                'next_run' => $nextRun,
                'overdue' => $overdue,
                'error' => $state['error'] ?? null,
            ];
        }

        return ApiResponse::create($data);
    }

    /**
     * GET /scheduler/status - Get scheduler cron status.
     *
     * Every field here is best-effort. A host that cannot report how its scheduler is
     * triggered must still get a usable Scheduler page, so nothing in this method may
     * take the response down with it (getgrav/grav-admin-next#16).
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        /** @var Scheduler $scheduler */
        $scheduler = $this->grav['scheduler'];

        // Ensure system jobs are registered so health status sees them
        $this->initializeSchedulerJobs($scheduler);

        $processAvailable = method_exists($scheduler, 'isProcessAvailable')
            ? $scheduler::isProcessAvailable()
            : function_exists('proc_open');

        $crontabStatus = 2;
        $detection = 'unavailable';
        try {
            $crontabStatus = $scheduler->isCrontabSetup();
            $detection = method_exists($scheduler, 'getCronDetectionMethod')
                ? $scheduler->getCronDetectionMethod()
                : 'crontab';
        } catch (\Throwable $e) {
            $this->grav['log']->warning('Scheduler cron detection unavailable: ' . $e->getMessage());
        }

        $statusMap = [0 => 'not_installed', 1 => 'installed', 2 => 'unknown'];

        // Health status and active triggers
        $health = [];
        $triggers = [];
        try {
            $health = method_exists($scheduler, 'getHealthStatus') ? $scheduler->getHealthStatus() : [];
            $triggers = method_exists($scheduler, 'getActiveTriggers') ? $scheduler->getActiveTriggers() : [];
        } catch (\Throwable $e) {
            $this->grav['log']->warning('Scheduler health unavailable: ' . $e->getMessage());
        }

        try {
            $whoami = $scheduler->whoami();
        } catch (\Throwable $e) {
            $whoami = 'unknown';
        }

        $lastManualRun = null;
        if (method_exists($scheduler, 'getLastManualRun')) {
            try {
                $stamp = $scheduler->getLastManualRun();
                $lastManualRun = $stamp ? date('c', $stamp) : null;
            } catch (\Throwable $e) {
                $lastManualRun = null;
            }
        }

        $lastRun = null;
        if (method_exists($scheduler, 'getLastRun')) {
            try {
                $stamp = $scheduler->getLastRun();
                $lastRun = $stamp ? date('c', $stamp) : null;
            } catch (\Throwable $e) {
                $lastRun = null;
            }
        }

        // The environment this request booted with, whether it carries overrides the bare CLI
        // ('cli') never loads, and the environment of the last real run. Together they let the
        // admin flag a crontab that is running a different configuration than the site (grav#4248).
        $environment = \Grav\Common\Config\Setup::$environment ?: null;
        $overrideEnvironment = method_exists($scheduler, 'getOverrideEnvironment') ? $scheduler->getOverrideEnvironment() : null;
        $lastRunEnvironment = method_exists($scheduler, 'getLastRunEnvironment') ? $scheduler->getLastRunEnvironment() : null;

        // Webhook plugin status
        $webhookInstalled = class_exists('Grav\\Plugin\\SchedulerWebhookPlugin')
            || is_dir($this->grav['locator']->findResource('plugin://scheduler-webhook') ?: '');
        $webhookEnabled = method_exists($scheduler, 'isWebhookEnabled') && $scheduler->isWebhookEnabled();

        // The command lines expose absolute bin/grav paths and the server user;
        // redact those for demo accounts while leaving the operational status
        // (installed/health/triggers) visible.
        $redact = $this->isDemoUser($request);

        $data = [
            'crontab_status' => $statusMap[$crontabStatus] ?? 'unknown',
            'cron_detection' => $detection,
            'process_available' => $processAvailable,
            'last_run' => $lastRun,
            'last_manual_run' => $lastManualRun,
            'environment' => $environment,
            'environment_has_overrides' => $overrideEnvironment !== null,
            'last_run_environment' => $lastRunEnvironment,
            'cron_command' => $redact ? self::DEMO_REDACTED : $scheduler->getCronCommand(),
            'scheduler_command' => $redact ? self::DEMO_REDACTED : $scheduler->getSchedulerCommand(),
            'whoami' => $redact ? self::DEMO_REDACTED : $whoami,
            'health' => $health,
            'triggers' => $triggers,
            'webhook_installed' => $webhookInstalled,
            'webhook_enabled' => $webhookEnabled,
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
     * POST /scheduler/run - Trigger a scheduler run manually.
     *
     * Body:
     *   mode  'overdue' (default) runs the jobs that missed their last scheduled slot,
     *         'due' runs only what a cron tick this minute would have run,
     *         'all' runs every enabled job whatever its schedule says.
     *   job   Run just this one job, whatever its schedule says.
     *   force Legacy alias for mode 'all'.
     *
     * The default is 'overdue' rather than 'due' on purpose. A job is only "due" during the
     * exact minute its cron expression names, so a plain due-run from a button press does
     * almost nothing -- which is not what anybody pressing it means.
     */
    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        /** @var Scheduler $scheduler */
        $scheduler = $this->grav['scheduler'];

        // Starting a job needs proc_open. Say so plainly rather than letting Symfony's
        // Process throw an unhandled exception (getgrav/grav-admin-next#16).
        $processAvailable = method_exists($scheduler, 'isProcessAvailable')
            ? $scheduler::isProcessAvailable()
            : function_exists('proc_open');
        if (!$processAvailable) {
            throw new ApiException(
                501,
                'Not Implemented',
                "Running scheduler jobs from the admin is not available on this host, because PHP's proc_open function is disabled. Jobs still run whenever your scheduler is triggered by cron or a webhook."
            );
        }

        $body = $this->getRequestBody($request);
        $force = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $jobId = isset($body['job']) && is_string($body['job']) && $body['job'] !== '' ? $body['job'] : null;

        $mode = is_string($body['mode'] ?? null) ? $body['mode'] : Scheduler::RUN_OVERDUE;
        if ($force) {
            $mode = Scheduler::RUN_ALL;
        }
        if (!in_array($mode, [Scheduler::RUN_DUE, Scheduler::RUN_OVERDUE, Scheduler::RUN_ALL], true)) {
            throw new ApiException(
                400,
                'Bad Request',
                sprintf("Unknown scheduler run mode '%s'. Use 'due', 'overdue' or 'all'.", (string) $mode)
            );
        }

        // Jobs are shell commands: a backup or a reindex can easily outlast the default web
        // request limit, and being cut off halfway leaves their state unrecorded. ini_set still
        // works on hosts where set_time_limit has been disabled.
        @ini_set('max_execution_time', '300');
        @set_time_limit(300);

        // Mark this as a run somebody asked for. Core keeps manual runs out of its cron
        // detection, so pressing this button cannot make a site with no crontab report a
        // healthy one.
        $scheduler->setRunTrigger('manual');
        $this->initializeSchedulerJobs($scheduler);

        $started = microtime(true);

        if (null !== $jobId) {
            $target = $scheduler->getJob($jobId);
            if (null === $target) {
                throw new ApiException(404, 'Not Found', sprintf("No scheduler job with id '%s'.", $jobId));
            }
            if (!$target->getEnabled()) {
                throw new ApiException(
                    409,
                    'Conflict',
                    sprintf("The job '%s' is disabled. Enable it before running it.", $jobId)
                );
            }

            $job = $scheduler->runJob($jobId);
            $jobsRun = null === $job ? [] : [$job];
            $mode = 'job';
        } else {
            $scheduler->run(null, false, $mode);
            $jobsRun = $scheduler->getJobsRun();
        }

        $results = [];
        $failed = 0;
        foreach ($jobsRun as $job) {
            $successful = $job->isSuccessful();
            if (!$successful) {
                $failed++;
            }

            $results[] = [
                'id' => $job->getId(),
                'successful' => $successful,
                'output' => $this->isDemoUser($request) ? self::DEMO_REDACTED : trim((string) $job->getOutput()),
            ];
        }

        $count = count($results);
        if ($count === 0) {
            $message = 'Nothing to run: no jobs were due.';
        } elseif ($failed === 0) {
            $message = sprintf('%d job%s ran successfully.', $count, $count === 1 ? '' : 's');
        } else {
            $message = sprintf('%d of %d job%s failed.', $failed, $count, $count === 1 ? '' : 's');
        }

        return ApiResponse::create([
            'message' => $message,
            'mode' => $mode,
            'forced' => $mode === Scheduler::RUN_ALL,
            'jobs_run' => $count,
            'jobs_failed' => $failed,
            'duration' => round(microtime(true) - $started, 2),
            'results' => $results,
            'job_states' => $scheduler->getJobStates()->content(),
        ]);
    }

    /**
     * GET /systeminfo - Generate system info overview.
     */
    public function systemInfo(ServerRequestInterface $request): ResponseInterface
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
