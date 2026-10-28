<?php

namespace Tests\Unit\Logging;

use App\Config\ConfigException;
use App\Logging\LogLevel;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Attributes\Parity;

/**
 * LOG_LEVEL as init_logging (src/main.rs) parses it.
 */
#[Parity('config:LOG_LEVEL')]
class LogLevelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function validLevels(): iterable
    {
        yield 'trace' => ['trace', 'debug'];
        yield 'debug' => ['debug', 'debug'];
        yield 'info' => ['info', 'info'];
        yield 'warn' => ['warn', 'warning'];
        yield 'error' => ['error', 'error'];
        yield 'off' => ['off', null];
        yield 'upper case' => ['WARN', 'warning'];
        yield 'one module' => ['info,vaultwarden::api::icons=debug', 'info'];
        yield 'several modules' => ['error,vaultwarden::db::query_logger=warn,rocket=OFF', 'error'];
        yield 'module after a space' => ['info, rocket=off', 'info'];
    }

    #[DataProvider('validLevels')]
    public function test_the_global_level_is_mapped_to_laravel(string $value, ?string $level): void
    {
        $this->assertSame($level, LogLevel::parse($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLevels(): iterable
    {
        yield 'empty' => [''];
        yield 'laravel level' => ['warning'];
        yield 'module only' => ['vaultwarden::api::icons=debug'];
        yield 'module without level' => ['info,vaultwarden::api::icons'];
        yield 'module with unknown level' => ['info,vaultwarden::api::icons=verbose'];
        yield 'trailing comma' => ['info,'];
        yield 'trailing newline' => ["info\n"];
    }

    #[DataProvider('invalidLevels')]
    public function test_other_values_are_refused(string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('LOG_LEVEL should follow the format info,vaultwarden::api::icons=debug, invalid: '.strtolower($value));

        LogLevel::parse($value);
    }

    public function test_every_channel_with_a_level_gets_it(): void
    {
        $config = new Repository(['logging' => ['default' => 'stack', 'channels' => [
            'stack' => ['driver' => 'stack', 'channels' => ['single']],
            'single' => ['driver' => 'single', 'level' => 'debug'],
            'slack' => ['driver' => 'slack', 'level' => 'critical'],
        ]]]);

        LogLevel::configure($config, 'warning');

        $this->assertSame('stack', $config->get('logging.default'));
        $this->assertSame(['driver' => 'stack', 'channels' => ['single']], $config->get('logging.channels.stack'));
        $this->assertSame('warning', $config->get('logging.channels.single.level'));
        $this->assertSame('warning', $config->get('logging.channels.slack.level'));
    }

    public function test_off_logs_to_the_null_channel(): void
    {
        $config = new Repository(['logging' => ['default' => 'stack', 'channels' => [
            'single' => ['driver' => 'single', 'level' => 'debug'],
        ]]]);

        LogLevel::configure($config, null);

        $this->assertSame('null', $config->get('logging.default'));
        $this->assertSame('debug', $config->get('logging.channels.single.level'));
    }
}
