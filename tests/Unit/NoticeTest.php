<?php

namespace Tests\Unit;

use App\Console\Commands\ParitySync;
use App\Parity\Reference;
use PHPUnit\Framework\TestCase;

/**
 * Keeps NOTICE.md complete: it names every file taken over from Vaultwarden,
 * every package Composer installs and the source of the web vault that the
 * release archives include.
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

    public function test_the_source_of_the_web_vault_in_the_release_archives_is_named_for_the_pinned_version(): void
    {
        $version = Reference::load(dirname(__DIR__, 2))->webVault;

        foreach (["https://github.com/vaultwarden/vw_web_builds/tree/v{$version}", "https://github.com/dani-garcia/bw_web_builds/tree/v{$version}", "`web-v{$version}`"] as $source) {
            $this->assertStringContainsString($source, $this->notice, "NOTICE.md must name {$source}, the source of the web vault the release archives include (GPL-3.0 section 6).");
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
