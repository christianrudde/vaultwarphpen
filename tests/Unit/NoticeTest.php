<?php

namespace Tests\Unit;

use App\Console\Commands\ParitySync;
use PHPUnit\Framework\TestCase;

/**
 * Keeps NOTICE.md complete: it names every file taken over from Vaultwarden
 * and every package Composer installs.
 */
class NoticeTest extends TestCase
{
    private string $notice;

    protected function setUp(): void
    {
        $this->notice = (string) file_get_contents(dirname(__DIR__, 2).'/NOTICE.md');
    }

    public function test_every_copied_directory_is_credited(): void
    {
        foreach (ParitySync::MAPPINGS as [$source, $target]) {
            $this->assertStringContainsString("`{$target}`", $this->notice, "NOTICE.md does not name {$target}, copied from Vaultwarden's {$source}.");
        }
    }

    public function test_every_locked_package_is_credited(): void
    {
        /** @var array{packages: list<array{name: string}>, packages-dev: list<array{name: string}>} $lock */
        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            $this->assertStringContainsString("`{$package['name']}`", $this->notice, "NOTICE.md does not list {$package['name']}.");
        }
    }

    public function test_every_credited_package_is_locked(): void
    {
        $lock = (string) file_get_contents(dirname(__DIR__, 2).'/composer.lock');
        preg_match_all('#^\| `([a-z0-9_.-]+/[a-z0-9_.-]+)` \|#m', $this->notice, $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $name) {
            $this->assertStringContainsString("\"name\": \"{$name}\"", $lock, "NOTICE.md lists {$name}, which composer.lock no longer has.");
        }
    }
}
