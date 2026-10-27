<?php

namespace Tests\Unit\Support;

use App\Config\Settings;
use App\Support\CronSchedule;
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
    }

    #[DataProvider('expressions')]
    public function test_expressions(string $expression, bool $valid): void
    {
        $this->assertSame($valid, CronSchedule::isValid($expression));
    }
}
