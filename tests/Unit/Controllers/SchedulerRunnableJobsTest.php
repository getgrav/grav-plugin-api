<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Scheduler\Job;
use Grav\Common\Scheduler\Scheduler;
use Grav\Plugin\Api\Controllers\SchedulerController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * On a host that disables proc_open for the web server's PHP, jobs that call a PHP function
 * (backups, cache purges) can still be run from the admin; only command jobs can't
 * (getgrav/grav#4397).
 */
#[CoversClass(SchedulerController::class)]
class SchedulerRunnableJobsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!method_exists(Scheduler::class, 'canRun')) {
            $this->markTestSkipped('Needs Grav 2.2.30+ (Scheduler::canRun).');
        }
    }

    /** A job with just its command set; the constructor needs a Grav container. */
    private function job(mixed $command): Job
    {
        $job = (new ReflectionClass(Job::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Job::class, 'command'))->setValue($job, $command);

        return $job;
    }

    private function canRunHere(Scheduler $scheduler, Job $job): bool
    {
        $controller = (new ReflectionClass(SchedulerController::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod($controller, 'canRunHere'))->invoke($controller, $scheduler, $job);
    }

    #[Test]
    public function without_proc_open_only_function_jobs_are_runnable(): void
    {
        $scheduler = (new ReflectionClass(NoProcOpenScheduler::class))->newInstanceWithoutConstructor();

        $this->assertTrue($this->canRunHere($scheduler, $this->job(static fn () => 'ok')));
        $this->assertTrue($this->canRunHere($scheduler, $this->job('Grav\Common\Backup\Backups::backup')));
        $this->assertFalse($this->canRunHere($scheduler, $this->job('bin/grav')));
    }

    #[Test]
    public function with_proc_open_every_job_is_runnable(): void
    {
        $scheduler = (new ReflectionClass(Scheduler::class))->newInstanceWithoutConstructor();

        $this->assertTrue($this->canRunHere($scheduler, $this->job('bin/grav')));
    }
}

class NoProcOpenScheduler extends Scheduler
{
    public static function isProcessAvailable(): bool
    {
        return false;
    }
}
