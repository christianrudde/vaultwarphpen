<?php

namespace App\Support;

use App\Config\Config;
use App\Models\Device;

/**
 * src/api/push.rs: mobile push notifications through Bitwarden's relay
 * (PUSH_ENABLED). The relay is not ported yet: with push enabled, devices
 * are not registered there, which is logged.
 */
final class Push
{
    /**
     * register_push_device: only mobile devices with a push token.
     */
    public static function registerDevice(Device $device): void
    {
        if (! app(Config::class)->bool('push_enabled') || ! $device->isPushDevice() || $device->push_token === null) {
            return;
        }
        logger()->warning("Push notifications are not supported yet; device {$device->uuid} is not registered with the relay.");
    }

    /**
     * unregister_push_device
     */
    public static function unregisterDevice(?string $pushUuid): void
    {
        if (! app(Config::class)->bool('push_enabled') || $pushUuid === null) {
            return;
        }
        logger()->warning("Push notifications are not supported yet; device {$pushUuid} is not unregistered from the relay.");
    }
}
