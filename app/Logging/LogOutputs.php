<?php

namespace App\Logging;

use App\Config\Config;
use Illuminate\Contracts\Config\Repository;
use Monolog\Handler\SyslogHandler;

/**
 * Where init_logging (src/main.rs) sends the log, applied to Laravel's
 * channels: the lines in Vaultwarden's format (EXTENDED_LOGGING,
 * LOG_TIMESTAMP_FORMAT), and besides the default channel, which stands in
 * for standard output, the file LOG_FILE and with USE_SYSLOG the syslog,
 * as process "vaultwarden" with the user facility.
 */
final class LogOutputs
{
    /**
     * The channels that write lines and have no formatter of their own.
     */
    private const array FORMATTED = ['single', 'daily', 'monthly', 'stderr', 'errorlog', 'syslog'];

    public static function configure(Repository $config, Config $settings, string $level): void
    {
        $formatter = ['formatter' => VaultwardenFormatter::class, 'formatter_with' => [
            'extended' => $settings->bool('extended_logging'),
            'timestampFormat' => $settings->string('log_timestamp_format'),
        ]];
        foreach (self::FORMATTED as $name) {
            $channel = $config->get("logging.channels.{$name}");
            if (is_array($channel) && ! isset($channel['formatter'])) {
                $config->set("logging.channels.{$name}", [...$channel, ...$formatter]);
            }
        }

        $outputs = [];
        $file = $settings->optionalString('log_file');
        if ($file !== null) {
            $config->set('logging.channels.vaultwarden_file', ['driver' => 'single', 'path' => $settings->absolutePath($file), 'level' => $level, ...$formatter]);
            $outputs[] = 'vaultwarden_file';
        }
        if ($settings->bool('use_syslog')) {
            $config->set('logging.channels.vaultwarden_syslog', ['driver' => 'monolog', 'handler' => SyslogHandler::class,
                'handler_with' => ['ident' => 'vaultwarden', 'facility' => LOG_USER], 'level' => $level, ...$formatter]);
            $outputs[] = 'vaultwarden_syslog';
        }

        if ($outputs !== []) {
            $config->set('logging.channels.vaultwarden', ['driver' => 'stack', 'channels' => [$config->get('logging.default'), ...$outputs],
                'ignore_exceptions' => false]);
            $config->set('logging.default', 'vaultwarden');
        }
    }
}
