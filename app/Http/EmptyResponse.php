<?php

namespace App\Http;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rocket's `()` responder: status 200, no body and no Content-Type, which
 * Symfony would otherwise add (and PHP too, see public/index.php).
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
