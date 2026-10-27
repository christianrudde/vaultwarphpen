<?php

namespace App\Http\Middleware;

use App\Http\HttpStatus;
use App\Support\Uuid;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Path parameters typed as ids (UuidFromParam): Rocket parses them before
 * any request guard, and a failure forwards the request with status 422.
 */
final class UuidParameters
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        foreach ($parameters as $parameter) {
            $value = $request->route($parameter);
            if (! is_string($value) || ! Uuid::isValid($value)) {
                throw new HttpStatus(422, "Path parameter `{$parameter}` is not a UUID.");
            }
        }

        return $next($request);
    }
}
