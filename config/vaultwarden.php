<?php

// Settings keep Vaultwarden's environment variable names, so an existing
// Vaultwarden .env can be reused unchanged.

$dataFolder = env('DATA_FOLDER', base_path('data'));

return [

    /*
    |--------------------------------------------------------------------------
    | Folders
    |--------------------------------------------------------------------------
    |
    | The data folder has the same layout as Vaultwarden's: database,
    | attachments, sends, icon cache, RSA key and template overrides.
    |
    */

    'data_folder' => $dataFolder,

    'templates_folder' => env('TEMPLATES_FOLDER', $dataFolder.'/templates'),

    'reload_templates' => (bool) env('RELOAD_TEMPLATES', false),

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
