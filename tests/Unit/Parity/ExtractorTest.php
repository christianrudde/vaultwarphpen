<?php

namespace Tests\Unit\Parity;

use App\Parity\Extractors\ConfigExtractor;
use App\Parity\Extractors\RouteExtractor;
use App\Parity\RustSource;
use PHPUnit\Framework\TestCase;

class ExtractorTest extends TestCase
{
    private RustSource $source;

    protected function setUp(): void
    {
        $this->source = new RustSource(dirname(__DIR__, 2).'/Fixtures/vaultwarden');
    }

    public function test_routes_follow_mounts_and_nested_route_functions(): void
    {
        $routes = new RouteExtractor($this->source)->extract();

        $this->assertSame([
            'route:GET /alive',
            'route:GET /',
            'route:GET /{p..}',
            'route:GET /api/alive',
            'route:GET /api/version',
            'route:POST /api/accounts/register',
            'route:GET /api/users/{user_id}/public-key',
            'route:PUT /api/accounts/avatar',
            'route:GET /api/two-factor',
            'route:POST /events/collect',
        ], array_column($routes, 'id'));
    }

    public function test_routes_describe_handler_guards_body_and_query(): void
    {
        $routes = array_column(new RouteExtractor($this->source)->extract(), null, 'id');

        $this->assertSame([
            'id' => 'route:GET /api/users/{user_id}/public-key',
            'kind' => 'route',
            'method' => 'GET',
            'path' => '/api/users/{user_id}/public-key',
            'query' => ['include_keys'],
            'handler' => 'core::accounts::get_public_keys',
            'source' => 'src/api/core/accounts.rs:16',
            'guards' => ['Headers'],
        ], $routes['route:GET /api/users/{user_id}/public-key']);

        $this->assertSame('Json<AvatarData>', $routes['route:PUT /api/accounts/avatar']['body']);
        $this->assertSame(['Headers'], $routes['route:PUT /api/accounts/avatar']['guards']);
        $this->assertSame('format = "application/json"', $routes['route:POST /events/collect']['options']);
        $this->assertSame('rank = 10', $routes['route:GET /{p..}']['options']);
    }

    public function test_unmounted_route_attributes_are_reported(): void
    {
        $extractor = new RouteExtractor($this->source);
        $extractor->extract();

        $this->assertSame(
            ['Route GET /never-mounted (src/api/web.rs:24) is not mounted.'],
            $extractor->warnings,
        );
    }

    public function test_settings_and_jobs_are_read_from_make_config(): void
    {
        $extractor = new ConfigExtractor($this->source);
        $items = array_column($extractor->extract(), null, 'id');

        $this->assertSame([], $extractor->warnings);
        $this->assertSame(
            [
                'config:JOB_POLL_INTERVAL_MS', 'config:TRASH_PURGE_SCHEDULE', 'config:PURGE_INCOMPLETE_SSO_AUTH',
                'config:_ENABLE_SMTP', 'config:SMTP_SECURITY', 'config:SMTP_PORT', 'job:trash_purge', 'job:purge_incomplete_sso_auth',
            ],
            array_keys($items),
        );
        $this->assertSame('Trash purge schedule', $items['config:TRASH_PURGE_SCHEDULE']['title']);
        $this->assertSame(
            'Cron schedule of the job that checks for trashed items to delete permanently. Defaults to daily. Set blank to disable this job.',
            $items['config:TRASH_PURGE_SCHEDULE']['description'],
        );
        $this->assertSame('|c| convert(c.smtp_ssl)', $items['config:SMTP_SECURITY']['default']);
        $this->assertSame('smtp', $items['config:SMTP_PORT']['group']);
        $this->assertSame('0 5 0 * * *', $items['job:trash_purge']['schedule']);
        $this->assertSame('PURGE_INCOMPLETE_SSO_AUTH', $items['job:purge_incomplete_sso_auth']['config']);
    }
}
