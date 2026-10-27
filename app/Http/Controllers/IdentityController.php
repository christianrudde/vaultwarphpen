<?php

namespace App\Http\Controllers;

use App\Auth\ClientIp;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Config\Config;
use App\Http\Accept;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\RegisterData;
use App\Http\EmptyResponse;
use App\Http\Json;
use App\Mail\Mail;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Dates;
use App\Support\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * src/api/identity.rs
 */
final readonly class IdentityController
{
    public function __construct(
        private Config $config,
        private Jwt $jwt,
        private RateLimiter $limiter,
        private AccountsController $accounts,
    ) {}

    /**
     * POST /identity/accounts/prelogin
     */
    public function postPrelogin(Request $request): Response
    {
        return $this->prelogin($request);
    }

    /**
     * POST /identity/accounts/prelogin/password
     */
    public function preloginPassword(Request $request): Response
    {
        return $this->prelogin($request);
    }

    /**
     * POST /identity/accounts/register/send-verification-email: without mail
     * verification the token comes back directly, as JSON only when the
     * client asks for it.
     */
    public function registerVerificationEmail(Request $request): Response
    {
        $body = Body::json($request);
        $email = $body->string('email');
        $name = $body->optionalString('name');

        $this->limiter->check('unauthenticated', ClientIp::resolve($request, $this->config));

        // Signup must be allowed, or an invitation exist.
        if (! ($this->config->isSignupAllowed($email)
            || (! $this->config->mailEnabled() && Invitation::findByMail($email) !== null))) {
            throw ApiError::message('Registration not allowed or user already exists');
        }

        $sendMail = $this->config->mailEnabled() && $this->config->bool('signups_verify');
        $now = Dates::now()->getTimestamp();
        // RegisterVerifyClaims, valid for 30 minutes.
        $token = $this->jwt->encode([
            'nbf' => $now,
            'exp' => $now + 1_800,
            'iss' => $this->jwt->issuer(JwtIssuer::RegisterVerify),
            'sub' => $email,
            'name' => $name,
            'verified' => $sendMail,
        ]);

        if ($sendMail) {
            $user = User::findByMail($email);
            if ($user !== null && $user->private_key !== null) {
                // Hide that the account exists: the mail path takes about as long.
                usleep(random_int(900, 1_100) * 1_000);
            } else {
                Mail::send('register_verify_email', $email, ['token' => $token]);
            }

            return (new EmptyResponse)->setStatusCode(204);
        }

        return Accept::prefersJson($request)
            ? Json::response($token)
            : new Response($token, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * POST /identity/accounts/register/finish
     */
    public function registerFinish(Request $request): Response
    {
        $data = RegisterData::fromFields(Body::json($request));
        $this->limiter->check('unauthenticated', ClientIp::resolve($request, $this->config));

        return $this->accounts->register($data);
    }

    private function prelogin(Request $request): Response
    {
        $email = Body::json($request)->string('email');

        return $this->accounts->prelogin($email, ClientIp::resolve($request, $this->config));
    }
}
