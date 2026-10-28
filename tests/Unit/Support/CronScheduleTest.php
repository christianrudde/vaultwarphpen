<?php

namespace Tests\Unit\Support;

use App\Config\Settings;
use App\Support\CronSchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CronScheduleTest extends TestCase
{
    public function test_vaultwarden_default_schedules_are_valid(): void
    {
        $schedules = 0;
        foreach (Settings::all() as $setting) {
            if (str_ends_with($setting->name, '_schedule') || $setting->name === 'purge_incomplete_sso_auth') {
                $this->assertIsString($setting->default, $setting->env());
                $this->assertTrue(CronSchedule::isValid($setting->default), $setting->env());
                $schedules++;
            }
        }

        $this->assertSame(9, $schedules);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function expressions(): iterable
    {
        yield 'with year' => ['0 0 12 1 JAN * 2030', true];
        yield 'names written out' => ['0 0 9 * * Monday', true];
        yield 'steps and ranges' => ['*/10 0-30/5 8-18 1,15 1-6 2-6', true];
        yield 'question mark for days' => ['0 0 0 ? * MON', true];
        yield 'shorthand' => ['@hourly', true];
        yield 'five fields' => ['0 5 * * *', false];
        yield 'eight fields' => ['0 0 0 1 1 1 2030 1', false];
        yield 'second out of range' => ['60 * * * * *', false];
        yield 'day zero' => ['0 0 0 0 * *', false];
        yield 'reversed range' => ['0 30-10 * * * *', false];
        yield 'zero step' => ['*/0 * * * * *', false];
        yield 'question mark for hours' => ['0 0 ? * * *', false];
        yield 'unknown name' => ['0 0 0 * FOO *', false];
        yield 'shorthand in capitals' => ['@HOURLY', false];
        yield 'unknown shorthand' => ['@annually', false];
        yield 'step above the maximum' => ['*/60 * * * * *', false];
        yield 'point with step' => ['5/15 * * * * *', true];
        yield 'step beyond the range' => ['30/45 * * * * *', true];
        yield 'named range with step' => ['0 0 0 * * Mon-Fri/2', true];
        yield 'named point with step' => ['0 0 0 * * Wed/2', false];
        yield 'longer abbreviations' => ['0 0 0 * * Tues,THURS', true];
        yield 'names for hours' => ['0 0 Mon * * *', false];
        yield 'year out of range' => ['0 0 0 1 1 * 2101', false];
    }

    #[DataProvider('expressions')]
    public function test_expressions(string $expression, bool $valid): void
    {
        $this->assertSame($valid, CronSchedule::isValid($expression));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function nextTimes(): iterable
    {
        yield 'strictly after' => ['30 * * * * *', '2026-10-28 12:00:30', '2026-10-28 12:01:30'];
        yield 'within the second' => ['30 * * * * *', '2026-10-28 12:00:29.500000', '2026-10-28 12:00:30'];
        yield 'hourly' => ['0 5 * * * *', '2026-10-28 12:05:00', '2026-10-28 13:05:00'];
        yield 'into the next year' => ['0 5 0 * * *', '2026-12-31 23:59:59', '2027-01-01 00:05:00'];
        yield 'leap day' => ['0 0 0 29 2 *', '2026-03-01 00:00:00', '2028-02-29 00:00:00'];
        yield 'day of month and weekday' => ['0 0 12 13 * Fri', '2026-10-28 00:00:00', '2026-11-13 12:00:00'];
        yield 'Sunday is the first day' => ['0 0 0 * * 1', '2026-10-28 00:00:00', '2026-11-01 00:00:00'];
        yield 'weekly' => ['@weekly', '2026-10-28 00:00:00', '2026-11-01 00:00:00'];
        yield 'ranges and steps' => ['*/20 0-30/15 8 * * *', '2026-10-28 08:15:41', '2026-10-28 08:30:00'];
        yield 'step beyond the range' => ['30/45 * * * * *', '2026-10-28 08:00:31', '2026-10-28 08:01:30'];
        yield 'year' => ['0 0 12 1 JAN * 2030', '2026-10-28 00:00:00', '2030-01-01 12:00:00'];
        yield 'past year' => ['0 0 0 * * * 2025', '2026-10-28 00:00:00', null];
        yield 'other zone' => ['0 0 0 * * *', '2026-10-28 01:00:00+02:00', '2026-10-28 00:00:00'];
    }

    #[DataProvider('nextTimes')]
    public function test_next_time(string $expression, string $after, ?string $next): void
    {
        $schedule = CronSchedule::parse($expression);
        $this->assertNotNull($schedule);

        $time = $schedule->nextAfter(new DateTimeImmutable($after, new DateTimeZone('UTC')));
        $this->assertSame($next, $time?->format('Y-m-d H:i:s'));
        $this->assertSame($next === null ? null : 'UTC', $time?->getTimezone()->getName());
    }
}
