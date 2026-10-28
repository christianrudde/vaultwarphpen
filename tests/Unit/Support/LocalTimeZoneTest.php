<?php

namespace Tests\Unit\Support;

use App\Support\LocalTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The zone of chrono's Local: TZ, else /etc/localtime, else UTC.
 */
class LocalTimeZoneTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/vw-zone-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));
    }

    public function test_tz_names_the_zone(): void
    {
        $this->assertSame('Europe/Berlin', LocalTimeZone::resolve('Europe/Berlin', "{$this->folder}/none")->getName());
        $this->assertSame('Asia/Tokyo', LocalTimeZone::resolve(':Asia/Tokyo', "{$this->folder}/none")->getName());
    }

    public function test_tz_without_a_zone_is_utc(): void
    {
        foreach (['', 'CET-1CEST,M3.5.0,M10.5.0/3', 'Nowhere/Atlantis'] as $tz) {
            $this->assertSame('UTC', LocalTimeZone::resolve($tz, "{$this->folder}/none")->getName(), $tz);
        }
    }

    public function test_tz_may_name_a_zone_file(): void
    {
        symlink('/usr/share/zoneinfo/America/New_York', "{$this->folder}/link");

        $this->assertSame('America/New_York', LocalTimeZone::resolve("{$this->folder}/link")->getName());
        $this->assertSame('America/New_York', LocalTimeZone::resolve(":{$this->folder}/link")->getName());
    }

    public function test_without_tz_the_zone_localtime_links_to(): void
    {
        // As on Linux, and as on macOS, whose zone files lie elsewhere.
        symlink('../usr/share/zoneinfo/Europe/Paris', "{$this->folder}/relative");
        symlink('/var/db/timezone/zoneinfo/Australia/Sydney', "{$this->folder}/macos");

        $this->assertSame('Europe/Paris', LocalTimeZone::resolve(false, "{$this->folder}/relative")->getName());
        $this->assertSame('Australia/Sydney', LocalTimeZone::resolve(false, "{$this->folder}/macos")->getName());
    }

    public function test_a_copied_localtime_goes_by_etc_timezone(): void
    {
        file_put_contents("{$this->folder}/localtime", 'TZif2');
        file_put_contents("{$this->folder}/timezone", "Europe/Vienna\n");

        $this->assertSame('Europe/Vienna', LocalTimeZone::resolve(false, "{$this->folder}/localtime", "{$this->folder}/timezone")->getName());
        $this->assertSame('UTC', LocalTimeZone::resolve(false, "{$this->folder}/localtime", "{$this->folder}/none")->getName());
        $this->assertSame('UTC', LocalTimeZone::resolve(false, "{$this->folder}/none", "{$this->folder}/timezone")->getName());
    }
}
