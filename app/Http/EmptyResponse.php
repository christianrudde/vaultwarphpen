<?php

namespace App\Http;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rocket's `()` responder: status 200, no body and no Content-Type.
 *
 * Laravel's responses extend Symfony's HttpFoundation Response, whose
 * prepare() (called by Laravel's router) adds `text/html` when no type is
 * set; it is removed again here. PHP itself would add one too, which
 * public/index.php switches off.
 */
final class EmptyResponse extends Response
{
    public function __construct()
    {
        parent::__construct('', 200);
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);
        $this->headers->remove('Content-Type');

        return $this;
    }
}
