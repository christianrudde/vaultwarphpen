<?php

namespace App\Http;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rocket's Redirect::to: 303 See Other with the Location, no body and no
 * Content-Type (see EmptyResponse for why it is removed again).
 */
final class Redirect extends Response
{
    /**
     * @param  int  $status  303, or 307 for Redirect::temporary
     */
    public function __construct(string $location, int $status = 303)
    {
        parent::__construct('', $status, ['Location' => $location]);
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);
        $this->headers->remove('Content-Type');

        return $this;
    }
}
