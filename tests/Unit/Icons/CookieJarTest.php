<?php

namespace Tests\Unit\Icons;

use App\Support\CookieJar;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Uri\WhatWg\Url;

/**
 * The cookie jar of the icon client (Jar::set_cookies in src/api/icons.rs).
 */
class CookieJarTest extends TestCase
{
    public function test_expired_cookies_are_not_kept(): void
    {
        // Vaultwarden e2d63cfc removes expired cookies when it stores new ones; the port drops them when it loads the jar.
        $jar = CookieJar::load();
        $jar->store(['fresh=1'], new Url('https://example.com/'));
        $jar->save();
        $stored = Cache::get('icons:cookie-jar');
        $this->assertIsArray($stored);
        $stored['example.com;/;stale'] = ['name' => 'stale', 'value' => '1', 'domain' => 'example.com', 'hostOnly' => true, 'path' => '/',
            'secure' => false, 'expires' => time() - 1];
        Cache::put('icons:cookie-jar', $stored, 120);

        $jar = CookieJar::load();
        $this->assertSame('fresh=1', $jar->header(new Url('https://example.com/')));
        $jar->store(['other=2'], new Url('https://example.com/'));
        $jar->save();
        $this->assertSame(['example.com;/;fresh', 'example.com;/;other'], array_keys((array) Cache::get('icons:cookie-jar')));
    }
}
