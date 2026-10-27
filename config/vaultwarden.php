<?php

// Vaultwarden's own settings (DATA_FOLDER, DOMAIN, ...) are not Laravel
// configuration; they are read like Vaultwarden reads them, see
// App\Config\Config.

return [

    /*
    |--------------------------------------------------------------------------
    | Upstream versions
    |--------------------------------------------------------------------------
    |
    | The Vaultwarden release and web vault build this implementation mirrors
    | (see parity/reference.json). Templates compare against them through the
    | "vwver" and "webver" helpers.
    |
    */

    'version' => '1.37.4',

    'web_vault_version' => '2026.8.0',

];
