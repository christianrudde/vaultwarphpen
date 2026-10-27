<?php

namespace App\Models;

/**
 * Bitwarden's device types (DeviceType in src/db/models/device.rs).
 */
enum DeviceType: int
{
    case Android = 0;
    case Ios = 1;
    case ChromeExtension = 2;
    case FirefoxExtension = 3;
    case OperaExtension = 4;
    case EdgeExtension = 5;
    case WindowsDesktop = 6;
    case MacOsDesktop = 7;
    case LinuxDesktop = 8;
    case ChromeBrowser = 9;
    case FirefoxBrowser = 10;
    case OperaBrowser = 11;
    case EdgeBrowser = 12;
    case IEBrowser = 13;
    case UnknownBrowser = 14;
    case AndroidAmazon = 15;
    case Uwp = 16;
    case SafariBrowser = 17;
    case VivaldiBrowser = 18;
    case VivaldiExtension = 19;
    case SafariExtension = 20;
    case Sdk = 21;
    case Server = 22;
    case WindowsCLI = 23;
    case MacOsCLI = 24;
    case LinuxCLI = 25;
    case DuckDuckGoBrowser = 26;

    /**
     * Unknown numbers count as an unknown browser.
     */
    public static function fromInt(int $value): self
    {
        return self::tryFrom($value) ?? self::UnknownBrowser;
    }

    /**
     * The name used in the login token's `devicetype` claim.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Android, self::AndroidAmazon => 'Android',
            self::Ios => 'iOS',
            self::ChromeExtension => 'Chrome Extension',
            self::FirefoxExtension => 'Firefox Extension',
            self::OperaExtension => 'Opera Extension',
            self::EdgeExtension => 'Edge Extension',
            self::WindowsDesktop => 'Windows',
            self::MacOsDesktop => 'macOS',
            self::LinuxDesktop => 'Linux',
            self::ChromeBrowser => 'Chrome',
            self::FirefoxBrowser => 'Firefox',
            self::OperaBrowser => 'Opera',
            self::EdgeBrowser => 'Edge',
            self::IEBrowser => 'Internet Explorer',
            self::UnknownBrowser => 'Unknown Browser',
            self::Uwp => 'UWP',
            self::SafariBrowser => 'Safari',
            self::VivaldiBrowser => 'Vivaldi',
            self::VivaldiExtension => 'Vivaldi Extension',
            self::SafariExtension => 'Safari Extension',
            self::Sdk => 'SDK',
            self::Server => 'Server',
            self::WindowsCLI => 'Windows CLI',
            self::MacOsCLI => 'macOS CLI',
            self::LinuxCLI => 'Linux CLI',
            self::DuckDuckGoBrowser => 'DuckDuckGo',
        };
    }
}
