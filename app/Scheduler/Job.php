<?php

namespace App\Scheduler;

use App\Support\CronSchedule;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * A job of job_scheduler_ng: it runs at most once per tick, when its schedule
 * had a time since the previous tick. The first tick only notes the time.
 */
final class Job
{
    public ?DateTimeImmutable $lastTick = null;

    /**
     * @param  Closure(): void  $run
     */
    public function __construct(
        public readonly string $name,
        private readonly CronSchedule $schedule,
        private readonly Closure $run,
    ) {}

    public function tick(DateTimeImmutable $now): void
    {
        if ($this->lastTick === null) {
            $this->lastTick = $now;

            return;
        }

        $event = $this->schedule->nextAfter($this->lastTick);
        if ($event !== null && $event <= $now) {
            // Vaultwarden spawns the job as a task of its own; a failure does not stop the scheduler.
            try {
                ($this->run)();
            } catch (Throwable $error) {
                report($error);
            }
        }

        $this->lastTick = $now;
    }
}
