<?php

namespace App\Support;

use DateTimeZone;
use Throwable;

/**
 * The time zone of chrono's `Local` on Unix, where Vaultwarden shows local
 * times (admin panel, mails): the zone TZ names, or else the one
 * /etc/localtime is; UTC where neither works. PHP's own default zone does
 * not follow the system, and Laravel sets it to UTC.
 *
 * chrono reads the zone file itself; this only knows zones by name, so
 * POSIX rules in TZ ("CET-1CEST,...") count as UTC, and a copied
 * /etc/localtime is known by the name in /etc/timezone, which Debian keeps.
 */
final class LocalTimeZone
{
    public static function get(): DateTimeZone
    {
        return self::resolve(getenv('TZ'));
    }

    /**
     * @param  string|false  $tz  The TZ environment variable, false when unset
     */
    public static function resolve(string|false $tz, string $localtime = '/etc/localtime', string $timezone = '/etc/timezone'): DateTimeZone
    {
        if ($tz === false) {
            return self::ofFile($localtime, $timezone) ?? new DateTimeZone('UTC');
        }
        $name = str_starts_with($tz, ':') ? substr($tz, 1) : $tz;
        if (str_starts_with($name, '/')) {
            return self::ofFile($name) ?? new DateTimeZone('UTC');
        }

        return self::named(trim($name)) ?? new DateTimeZone('UTC');
    }

    /**
     * The zone a zone file is, by the name of the file its links lead to.
     */
    private static function ofFile(string $file, ?string $timezone = null): ?DateTimeZone
    {
        for ($hops = 0; $hops < 8 && is_link($file); $hops++) {
            $target = readlink($file);
            if ($target === false) {
                break;
            }
            if (preg_match('#(?:^|/)zoneinfo(?:\.default)?/(.+)$#', $target, $match) === 1 && ($zone = self::named($match[1])) !== null) {
                return $zone;
            }
            $file = str_starts_with($target, '/') ? $target : dirname($file).'/'.$target;
        }

        $name = $timezone !== null && is_file($timezone) ? trim((string) file_get_contents($timezone)) : '';

        return is_file($file) && $name !== '' ? self::named($name) : null;
    }

    private static function named(string $name): ?DateTimeZone
    {
        try {
            return $name === '' ? null : new DateTimeZone($name);
        } catch (Throwable) {
            return null;
        }
    }
}
