<?php

namespace App\Http\Controllers\TwoFactor;

use App\Auth\Headers;
use App\Auth\TwoFactorTokens;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\NumberOrString;
use App\Http\Data\PasswordOrOtpData;
use App\Http\EmptyResponse;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Models\Event;
use App\Models\TwoFactor;
use App\Support\Base32;
use App\Support\Random;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * src/api/core/two_factor/authenticator.rs: time-based one-time passwords
 * (TOTP, RFC 6238: SHA-1, six digits, 30 seconds).
 */
final readonly class AuthenticatorController
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * POST /api/two-factor/get-authenticator: the key in use, or a new one to set up.
     */
    public function generateAuthenticator(Headers $headers, Request $request): Response
    {
        $data = PasswordOrOtpData::fromFields(Body::json($request));
        $user = $headers->user;
        $data->validate($user, false, $this->config);

        $twoFactor = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_AUTHENTICATOR);
        [$enabled, $key] = $twoFactor === null ? [false, Random::base32(20)] : [true, $twoFactor->data];

        return Json::response(Json::sorted([
            'authenticator' => ['enabled' => $enabled, 'key' => $key],
            'userVerificationToken' => TwoFactorTokens::authenticator($user->uuid, $key, $enabled),
        ]));
    }

    /**
     * POST and PUT /api/two-factor/authenticator: a code of the new key turns it on.
     */
    public function activateAuthenticator(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $key = $body->string('key');
        $token = NumberOrString::optional($body, 'token') ?? throw new HttpStatus(422, 'Missing field `token`.');
        $verificationToken = $body->string('userVerificationToken');
        $user = $headers->user;

        TwoFactorTokens::validateAuthenticator($verificationToken, $user->uuid, $key, false);

        $decoded = Base32::decode($key) ?? throw ApiError::message('Invalid totp secret');
        if (strlen($decoded) !== 20) {
            throw ApiError::message('Invalid key length');
        }

        // A valid code stores the method.
        self::validateTotpCode($user->uuid, (string) $token, strtoupper($key), $headers->ip);
        TwoFactorController::generateRecoverCode($user);
        Event::logUser(Event::USER_UPDATED_2FA, $user->uuid, $headers->device->atype, $headers->ip);

        return Json::response(Json::sorted(['authenticator' => ['enabled' => true, 'key' => $key]]));
    }

    /**
     * DELETE /api/two-factor/authenticator: with the key in use.
     */
    public function disableAuthenticator(Headers $headers, Request $request): EmptyResponse
    {
        $body = Body::json($request);
        $key = $body->string('key');
        $verificationToken = $body->string('userVerificationToken');
        $user = $headers->user;

        TwoFactorTokens::validateAuthenticator($verificationToken, $user->uuid, $key, true);

        $twoFactor = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_AUTHENTICATOR);
        if ($twoFactor !== null) {
            if ($twoFactor->data !== $key) {
                throw ApiError::message("TOTP key for user {$user->email} does not match recorded value, cannot deactivate");
            }
            $twoFactor->deleteTwoFactor();
            Event::logUser(Event::USER_DISABLED_2FA, $user->uuid, $headers->device->atype, $headers->ip);
        }

        TwoFactorController::check2faState($user, $headers->device->atype, $headers->ip);

        return new EmptyResponse;
    }

    /**
     * validate_totp_code_str: the code of a login, which must be all digits.
     *
     * @throws ApiError
     */
    public static function validateTotpCodeStr(string $userUuid, string $code, string $secret, string $ip): void
    {
        if (! preg_match('/^\p{N}*$/uD', $code)) {
            throw ApiError::message('TOTP code is not a number');
        }
        self::validateTotpCode($userUuid, $code, $secret, $ip);
    }

    /**
     * validate_totp_code: the code of the current time step, or with time
     * drift allowed of the steps next to it. Each step counts once; the last
     * one used is stored, which also saves a method set up just now.
     *
     * @throws ApiError
     */
    public static function validateTotpCode(string $userUuid, string $code, string $secret, string $ip): void
    {
        $decoded = Base32::decode($secret) ?? throw ApiError::message('Invalid TOTP secret');
        $twoFactor = TwoFactor::findByUserAndType($userUuid, TwoFactor::TYPE_AUTHENTICATOR)
            ?? TwoFactor::new($userUuid, TwoFactor::TYPE_AUTHENTICATOR, $secret);

        $steps = app(Config::class)->bool('authenticator_disable_time_drift') ? 0 : 1;
        $now = time();
        $failure = ApiError::message('Invalid TOTP code! Server time: '.gmdate('Y-m-d H:i:s').' UTC IP: '.$ip, event: Event::USER_FAILED_LOG_IN_2FA);

        for ($step = -$steps; $step <= $steps; $step++) {
            $timeStep = intdiv($now, 30) + $step;
            if (self::totp($decoded, $now + $step * 30) !== $code) {
                continue;
            }
            if ($timeStep <= $twoFactor->last_used) {
                logger()->warning("This TOTP or a TOTP code within {$steps} steps back or forward has already been used!");

                throw $failure;
            }
            if ($step !== 0) {
                logger()->warning("TOTP Time drift detected. The step offset is {$step}");
            }
            $twoFactor->last_used = $timeStep;
            $twoFactor->save();

            return;
        }

        throw $failure;
    }

    /**
     * totp_lite::totp_custom::<Sha1>(30, 6, secret, time)
     */
    public static function totp(string $secret, int $time): string
    {
        $hash = hash_hmac('sha1', pack('J', intdiv($time, 30)), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
