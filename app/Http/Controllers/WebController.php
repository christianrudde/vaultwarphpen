<?php

namespace App\Http\Controllers;

use App\Http\Json;
use App\Support\Dates;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * src/api/web.rs
 */
final readonly class WebController
{
    public function __construct(
        private DatabaseManager $databases,
    ) {}

    /**
     * GET /alive
     */
    public function alive(): Response
    {
        $this->database();

        return Json::response(Dates::format(Dates::now()));
    }

    /**
     * HEAD /alive
     */
    public function aliveHead(): Response
    {
        $this->database();

        return new Response;
    }

    /**
     * The DbConn guard: without a database connection the request fails with 503.
     */
    private function database(): void
    {
        try {
            $this->databases->connection()->getPdo();
        } catch (Throwable $exception) {
            throw new HttpException(503, 'No database connection.', $exception);
        }
    }
}
