<?php

namespace App\Logging;

use App\Config\ConfigException;
use Illuminate\Contracts\Config\Repository;

/**
 * LOG_LEVEL as init_logging (src/main.rs) reads it, applied to Laravel's log channels.
 */
final class LogLevel
{
    /**
     * log::LevelFilter in Laravel's terms: Laravel has nothing finer than debug, and no off.
     */
    private const array LEVELS = [
        'off' => null,
        'error' => 'error',
        'warn' => 'warning',
        'info' => 'info',
        'debug' => 'debug',
        'trace' => 'debug',
    ];

    /**
     * The global level of a LOG_LEVEL like "info,vaultwarden::api::icons=debug",
     * or null for off. The levels for single modules are checked, but PHP has
     * no Rust modules to apply them to.
     *
     * @throws ConfigException
     */
    public static function parse(string $value): ?string
    {
        $levels = implode('|', array_keys(self::LEVELS));
        $value = mb_strtolower($value);

        if (preg_match("/^({$levels})((,[^,=]+=({$levels}))*)$/D", $value, $matches) !== 1) {
            throw new ConfigException("LOG_LEVEL should follow the format info,vaultwarden::api::icons=debug, invalid: {$value}");
        }

        return self::LEVELS[$matches[1]];
    }

    /**
     * Give every channel the level, or send everything to the null channel when it is off.
     */
    public static function configure(Repository $config, ?string $level): void
    {
        if ($level === null) {
            $config->set('logging.default', 'null');

            return;
        }

        $channels = $config->get('logging.channels');
        foreach (is_array($channels) ? $channels : [] as $name => $channel) {
            if (is_array($channel) && array_key_exists('level', $channel)) {
                $config->set("logging.channels.{$name}.level", $level);
            }
        }
    }
}
