<?php

namespace App\Auth\Sso;

use RuntimeException;

/**
 * DiscoveryError of openidconnect: why the provider could not be discovered, as it displays.
 */
final class DiscoveryError extends RuntimeException {}
