<?php

namespace App\Http\Controllers;

use App\Http\Json;
use App\Support\Dates;
use Illuminate\Http\Response;

/**
 * src/api/core/mod.rs
 */
final readonly class CoreController
{
    public function __construct(
        private WebController $web,
    ) {}

    /**
     * GET /api/alive
     */
    public function alive(): Response
    {
        return $this->web->alive();
    }

    /**
     * GET /api/now
     */
    public function now(): Response
    {
        return Json::response(Dates::format(Dates::now()));
    }

    /**
     * GET /api/version
     */
    public function version(): Response
    {
        return Json::response(config()->string('vaultwarden.version'));
    }
}
