<?php

namespace App\Config;

/**
 * Whether config.json was used, as shown in the admin diagnostics.
 */
enum ConfigFileStatus: string
{
    case NotFound = 'NotFound';
    case Loaded = 'Loaded';
    case Error = 'Error';
}
