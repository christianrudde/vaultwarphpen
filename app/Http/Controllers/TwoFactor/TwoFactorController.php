<?php

namespace App\Http\Controllers\TwoFactor;

use App\Auth\Headers;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\PasswordOrOtpData;
use App\Http\Json;
use App\Models\Device;
use App\Models\OrgPolicy;
use App\Models\TwoFactor;
use App\Models\User;
use App\Support\JsonValue;
use App\Support\Random;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

/**
 * src/api/core/two_factor/mod.rs: the user's two-factor methods, the
 * recovery code and what all methods share.
 */
final readonly class TwoFactorController
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * GET /api/two-factor: the methods that can be used on this server.
     */
    public function getTwofactor(Headers $headers): Response
    {
        $methods = [];
        foreach (TwoFactor::findByUser($headers->user->uuid) as $twoFactor) {
            if (self::isProviderUsable($twoFactor->atype, $twoFactor->data)) {
                $methods[] = $twoFactor->toApiProvider();
            }
        }

        return Json::response(Json::sorted(['data' => $methods, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * POST /api/two-factor/get-recover: the recovery code, after the password.
     */
    public function getRecover(Headers $headers, Request $request): Response
    {
        $data = PasswordOrOtpData::fromFields(Body::json($request));
        $data->validate($headers->user, true, $this->config);

        return Json::response(Json::sorted(['code' => $headers->user->totp_recover, 'object' => 'twoFactorRecover']));
    }

    /**
     * GET /api/two-factor/get-device-verification-settings: not supported, like upstream.
     */
    public function getDeviceVerificationSettings(Headers $headers): Response
    {
        return Json::response(['isDeviceVerificationSectionEnabled' => false, 'unknownDeviceVerificationEnabled' => false, 'object' => 'deviceVerificationSettings']);
    }

    /**
     * is_twofactor_provider_usable: whether the server can check the method.
     */
    public static function isProviderUsable(int $type, ?string $data): bool
    {
        $config = app(Config::class);

        return match ($type) {
            TwoFactor::TYPE_AUTHENTICATOR, TwoFactor::TYPE_RECOVERY_CODE => true,
            TwoFactor::TYPE_EMAIL => $config->bool('_enable_email_2fa'),
            TwoFactor::TYPE_DUO, TwoFactor::TYPE_ORGANIZATION_DUO => self::hasDuoData($data) || self::hasGlobalDuoCredentials(),
            TwoFactor::TYPE_YUBIKEY => $config->bool('_enable_yubico') && $config->optionalString('yubico_client_id') !== null && $config->optionalString('yubico_secret_key') !== null,
            TwoFactor::TYPE_WEBAUTHN => $config->isWebauthn2faSupported(),
            TwoFactor::TYPE_REMEMBER => ! $config->bool('disable_2fa_remember'),
            default => false,
        };
    }

    /**
     * generate_recover_code: the first method gives the user a recovery code.
     */
    public static function generateRecoverCode(User $user): void
    {
        if ($user->totp_recover === null) {
            $user->totp_recover = Random::base32(20);
            try {
                $user->save();
            } catch (ApiError) {
                // Ignored like upstream.
            }
        }
    }

    /**
     * check_2fa_state: after a method or key is removed, no device may skip
     * the rest; without methods the 2FA policy applies.
     *
     * @throws ApiError
     */
    public static function check2faState(User $user, int $deviceType, string $ip): void
    {
        Device::clearTwofactorRememberByUser($user->uuid);
        if (TwoFactor::findByUser($user->uuid)->isEmpty()) {
            OrgPolicy::enforceTwoFactorForUser($user, $user->uuid, $deviceType, $ip);
        }
    }

    /**
     * Duo keys of the user's own: host, ik and sk, none of them empty.
     */
    private static function hasDuoData(?string $data): bool
    {
        [$ok, $value] = $data === null ? [false, null] : JsonValue::decode($data);
        if (! $ok || ! $value instanceof stdClass) {
            return false;
        }
        foreach (['host', 'ik', 'sk'] as $field) {
            if (! is_string($value->{$field} ?? null) || $value->{$field} === '') {
                return false;
            }
        }

        return true;
    }

    private static function hasGlobalDuoCredentials(): bool
    {
        $config = app(Config::class);

        return $config->bool('_enable_duo') && $config->optionalString('duo_host') !== null
            && $config->optionalString('duo_ikey') !== null && $config->optionalString('duo_skey') !== null;
    }
}
