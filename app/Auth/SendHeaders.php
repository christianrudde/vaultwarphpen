<?php

namespace App\Auth;

use App\Http\Unauthorized;
use Illuminate\Http\Request;
use Throwable;

/**
 * auth::send::SendHeaders: the access token of a Send link, which the
 * send_access grant of /identity/connect/token hands out.
 */
final readonly class SendHeaders
{
    public function __construct(
        public string $sendId,
    ) {}

    /**
     * @throws Unauthorized
     */
    public static function fromRequest(Request $request, Jwt $jwt): self
    {
        $authorization = $request->headers->get('Authorization') ?? throw new Unauthorized('No access token provided');
        // Everything after the last "Bearer ", or the whole header without one.
        $position = strrpos($authorization, 'Bearer ');
        $token = $position === false ? $authorization : substr($authorization, $position + strlen('Bearer '));

        try {
            $claims = $jwt->decode($token, JwtIssuer::Send);
        } catch (Throwable) {
            throw new Unauthorized('Invalid claim');
        }

        // BasicJwtClaims
        return is_int($claims['nbf'] ?? null) && is_int($claims['exp'] ?? null) && is_string($claims['iss'] ?? null) && is_string($claims['sub'] ?? null)
            ? new self($claims['sub'])
            : throw new Unauthorized('Invalid claim');
    }
}
