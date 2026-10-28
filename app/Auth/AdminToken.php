<?php

namespace App\Auth;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\HttpStatus;
use Illuminate\Http\Request;

/**
 * api::admin::AdminToken: the admin session, a token in the VW_ADMIN cookie,
 * unless DISABLE_ADMIN_TOKEN leaves the admin panel to an authentication in
 * front of it. Failures are a 401, which the admin login catcher answers.
 */
final readonly class AdminToken
{
    public const string COOKIE_NAME = 'VW_ADMIN';

    /**
     * The fields of BasicJwtClaims, which the session token must have.
     */
    public const array CLAIMS = ['nbf' => 'int', 'exp' => 'int', 'iss' => 'string', 'sub' => 'string'];

    private function __construct(
        public string $ip,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromRequest(Request $request, Config $config, Jwt $jwt): self
    {
        $ip = ClientIp::resolve($request, $config);

        if (! $config->bool('disable_admin_token')) {
            $token = $request->cookies->get(self::COOKIE_NAME);
            if (! is_string($token)) {
                throw new HttpStatus(401, 'Unauthorized');
            }

            try {
                $jwt->decode($token, JwtIssuer::Admin, fields: self::CLAIMS);
            } catch (ApiError) {
                // Vaultwarden removes the cookie here, but Rocket discards that
                // change when the catcher answers the request.
                logger()->error("Invalid or expired admin JWT. IP: {$ip}.");

                throw new HttpStatus(401, 'Session expired');
            }
        }

        return new self($ip);
    }
}
