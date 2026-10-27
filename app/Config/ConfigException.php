<?php

namespace App\Config;

use RuntimeException;

/**
 * An invalid configuration; Vaultwarden refuses to start with one.
 */
final class ConfigException extends RuntimeException {}
