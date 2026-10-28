<?php

namespace App\Http\Controllers\TwoFactor;

use App\Auth\Headers;
use App\Auth\TwoFactorTokens;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\PasswordOrOtpData;
use App\Http\EmptyResponse;
use App\Http\Json;
use App\Models\Event;
use App\Models\TwoFactor;
use App\Models\User;
use App\Support\HttpClient;
use App\Support\JsonValue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use stdClass;

/**
 * src/api/core/two_factor/duo.rs: Duo with the user's own keys or the
 * global ones (DUO_HOST, DUO_IKEY, DUO_SKEY). The method stores DuoData
 * {host, ik, sk}, or nothing for the global keys. The login itself is
 * App\Auth\DuoOidc.
 */
final readonly class DuoController
{
    private const string DISABLED_MESSAGE_DEFAULT = '<To use the global Duo keys, please leave these fields untouched>';

    public function __construct(
        private Config $config,
    ) {}

    /**
     * POST /api/two-factor/get-duo: keys are shown obscured.
     */
    public function getDuo(Headers $headers, Request $request): Response
    {
        $data = PasswordOrOtpData::fromFields(Body::json($request));
        $user = $headers->user;
        $data->validate($user, false, $this->config);

        [$status, $keys] = self::userDuoData($user->uuid);
        [$enabled, $duo] = match ($status) {
            'global' => [true, self::message('<global_secret>')],
            'user' => [true, self::obscure($keys ?? throw new LogicException('User keys'))],
            default => [false, $keys === null ? null : self::message(self::DISABLED_MESSAGE_DEFAULT)],
        };

        return Json::response(Json::sorted([
            'duo' => self::keysJson($enabled, $duo),
            'userVerificationToken' => TwoFactorTokens::duo($user->uuid, $duo, $enabled),
        ]));
    }

    /**
     * POST and PUT /api/two-factor/duo: keys of the user's own, checked with
     * Duo; left as shown (or empty), the global keys.
     */
    public function activateDuo(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $host = $body->string('host');
        $clientSecret = $body->string('clientSecret');
        $clientId = $body->string('clientId');
        $verificationToken = $body->string('userVerificationToken');
        $user = $headers->user;

        TwoFactorTokens::validateDuo($verificationToken, $user->uuid, null, false);

        $custom = ! self::emptyOrDefault($host) && ! self::emptyOrDefault($clientSecret) && ! self::emptyOrDefault($clientId);
        if ($custom) {
            $keys = ['host' => $host, 'ik' => $clientId, 'sk' => $clientSecret];
            $stored = Json::encode($keys);
            try {
                self::apiRequest('GET', '/auth/v2/check', '', $keys);
            } catch (ApiError $error) {
                throw ApiError::message('Failed to validate Duo credentials', logMessage: $error->getMessage());
            }
            $shown = self::obscure($keys);
        } else {
            [$shown, $stored] = [self::message('<global_secret>'), ''];
        }

        TwoFactor::new($user->uuid, TwoFactor::TYPE_DUO, $stored)->save();
        TwoFactorController::generateRecoverCode($user);
        Event::logUser(Event::USER_UPDATED_2FA, $user->uuid, $headers->device->atype, $headers->ip);

        return Json::response(Json::sorted(['duo' => self::keysJson(true, $shown)]));
    }

    /**
     * DELETE /api/two-factor/duo: with the token for the keys as shown.
     */
    public function disableDuo(Headers $headers, Request $request): EmptyResponse
    {
        $verificationToken = Body::json($request)->string('userVerificationToken');
        $user = $headers->user;

        $twoFactor = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_DUO);
        if ($twoFactor !== null) {
            [$status, $keys] = self::toUserDuoData($twoFactor);
            $duo = match ($status) {
                'global' => self::message('<global_secret>'),
                'user' => self::obscure($keys ?? throw new LogicException('User keys')),
                default => null,
            };
            TwoFactorTokens::validateDuo($verificationToken, $user->uuid, $duo, true);
            $twoFactor->deleteTwoFactor();
            Event::logUser(Event::USER_DISABLED_2FA, $user->uuid, $headers->device->atype, $headers->ip);
        }

        TwoFactorController::check2faState($user, $headers->device->atype, $headers->ip);

        return new EmptyResponse;
    }

    /**
     * get_duo_keys_email: the keys a login uses, the global ones for unknown users.
     *
     * @return array{host: string, ik: string, sk: string}
     *
     * @throws ApiError
     */
    public static function keysForEmail(string $email): array
    {
        $user = User::findByMail($email);
        [$status, $keys] = $user === null ? ['global', self::global()] : self::userDuoData($user->uuid);

        return ($status === 'disabled' ? null : $keys) ?? throw ApiError::message("Can't fetch Duo Keys");
    }

    /**
     * DuoData::global
     *
     * @return array{host: string, ik: string, sk: string}|null
     */
    public static function global(): ?array
    {
        $config = app(Config::class);
        $host = $config->optionalString('duo_host');
        if (! $config->bool('_enable_duo') || $host === null) {
            return null;
        }

        return ['host' => $host, 'ik' => (string) $config->optionalString('duo_ikey'), 'sk' => (string) $config->optionalString('duo_skey')];
    }

    /**
     * get_user_duo_data: the status ('global', 'user' or 'disabled') and the
     * keys in use; for 'disabled' the global keys when there are some.
     *
     * @return array{string, array{host: string, ik: string, sk: string}|null}
     */
    private static function userDuoData(string $userUuid): array
    {
        $twoFactor = TwoFactor::findByUserAndType($userUuid, TwoFactor::TYPE_DUO);

        return $twoFactor === null ? ['disabled', self::global()] : self::toUserDuoData($twoFactor);
    }

    /**
     * to_user_duo_data: the user's own keys when stored, else the global ones.
     *
     * @return array{string, array{host: string, ik: string, sk: string}|null}
     */
    private static function toUserDuoData(TwoFactor $twoFactor): array
    {
        [$ok, $value] = JsonValue::decode($twoFactor->data);
        if ($ok && $value instanceof stdClass && is_string($value->host ?? null) && is_string($value->ik ?? null) && is_string($value->sk ?? null)) {
            return ['user', ['host' => $value->host, 'ik' => $value->ik, 'sk' => $value->sk]];
        }
        $global = self::global();

        return $global === null ? ['disabled', null] : ['global', $global];
    }

    /**
     * duo_api_request: a signed request to Duo's Auth API.
     *
     * @param  array{host: string, ik: string, sk: string}  $keys
     *
     * @throws ApiError
     */
    private static function apiRequest(string $method, string $path, string $params, array $keys): void
    {
        $date = gmdate('D, d M Y H:i:s +0000');
        $password = hash_hmac('sha1', implode("\n", [$date, $method, $keys['host'], $path, $params]), $keys['sk']);

        [$status] = HttpClient::send($method, "https://{$keys['host']}{$path}", [
            'Authorization' => 'Basic '.base64_encode("{$keys['ik']}:{$password}"),
            'User-Agent' => 'vaultwarden:Duo/1.0 (Rust)',
            'Date' => $date,
        ]);
        if ($status >= 400) {
            throw ApiError::message("HTTP status client error ({$status}) for url (https://{$keys['host']}{$path})");
        }
    }

    /**
     * @param  array{host: string, ik: string, sk: string}|null  $duo
     * @return array<string, mixed>
     */
    private static function keysJson(bool $enabled, ?array $duo): array
    {
        return ['enabled' => $enabled, 'host' => $duo['host'] ?? null, 'clientSecret' => $duo['sk'] ?? null, 'clientId' => $duo['ik'] ?? null];
    }

    /**
     * DuoData::msg
     *
     * @return array{host: string, ik: string, sk: string}
     */
    private static function message(string $message): array
    {
        return ['host' => $message, 'ik' => $message, 'sk' => $message];
    }

    /**
     * DuoData::obscure: the first four bytes stay. Shorter values make
     * upstream panic, which Rocket answers with a 500.
     *
     * @param  array{host: string, ik: string, sk: string}  $keys
     * @return array{host: string, ik: string, sk: string}
     */
    private static function obscure(array $keys): array
    {
        $obscure = static function (string $value): string {
            if (strlen($value) < 4 || (strlen($value) > 4 && (ord($value[4]) & 0xC0) === 0x80)) {
                throw new LogicException('Cannot obscure the Duo data');
            }

            return substr($value, 0, 4).'************';
        };

        return ['host' => $obscure($keys['host']), 'ik' => $obscure($keys['ik']), 'sk' => $obscure($keys['sk'])];
    }

    private static function emptyOrDefault(string $value): bool
    {
        return trim($value) === '' || $value === self::DISABLED_MESSAGE_DEFAULT;
    }
}
