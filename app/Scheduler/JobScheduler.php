<?php

namespace App\Scheduler;

use App\Config\Config;
use App\Support\CronSchedule;
use App\Support\Dates;
use LogicException;

/**
 * schedule_jobs in src/main.rs, with job_scheduler_ng's JobScheduler: the jobs
 * Vaultwarden's settings enable, ticked in the order Vaultwarden adds them.
 * Schedules are in UTC.
 */
final class JobScheduler
{
    /**
     * @param  list<Job>  $jobs
     */
    private function __construct(
        public readonly array $jobs,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $jobs = [];
        foreach (Jobs::SCHEDULES as $name => $setting) {
            $expression = $config->string($setting);
            if ($expression === '' || ! Jobs::enabled($name, $config)) {
                continue;
            }
            // ConfigValidator has checked the expression already.
            $schedule = CronSchedule::parse($expression) ?? throw new LogicException("Invalid schedule for {$name}");
            $jobs[] = new Job($name, $schedule, static fn () => Jobs::run($name));
        }

        return new self($jobs);
    }

    public function tick(): void
    {
        foreach ($this->jobs as $job) {
            $job->tick(Dates::now());
        }
    }
}
