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
use App\Support\JsonValue;
use App\Support\Yubico;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

/**
 * src/api/core/two_factor/yubikey.rs: up to five YubiKeys, known by the
 * first 12 characters of their OTPs, which the Yubico servers check. The
 * method stores YubikeyMetadata: {keys, nfc}.
 */
final readonly class YubikeyController
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * POST /api/two-factor/get-yubikey
     */
    public function generateYubikey(Headers $headers, Request $request): Response
    {
        $data = PasswordOrOtpData::fromFields(Body::json($request));
        Yubico::credentials();
        $user = $headers->user;
        $data->validate($user, false, $this->config);

        $twoFactor = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_YUBIKEY);
        if ($twoFactor === null) {
            [$enabled, $keys, $yubikey] = [false, [], ['enabled' => false]];
        } else {
            ['keys' => $keys, 'nfc' => $nfc] = self::metadata($twoFactor->data);
            $enabled = $keys !== [];
            $yubikey = [...self::keyFields($keys), 'enabled' => $enabled, 'nfc' => $nfc];
        }

        return Json::response(Json::sorted([
            'yubiKey' => $yubikey,
            'userVerificationToken' => TwoFactorTokens::yubikey($user->uuid, $keys, $enabled),
        ]));
    }

    /**
     * POST and PUT /api/two-factor/yubikey: the keys replace the ones stored.
     * A full OTP is checked; a key id of 12 characters is taken as it is.
     */
    public function activateYubikey(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $given = [];
        foreach (['key1', 'key2', 'key3', 'key4', 'key5'] as $field) {
            $given[] = $body->optionalString($field);
        }
        $nfc = $body->bool('nfc');
        $verificationToken = $body->string('userVerificationToken');
        $user = $headers->user;

        $keys = array_values(array_filter($given, static fn (?string $key): bool => $key !== null && $key !== ''));
        if ($keys === []) {
            // Saving no keys would lock the user out; deactivating removes them all.
            throw ApiError::message('A key is required.');
        }

        $twoFactor = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_YUBIKEY);
        if ($twoFactor !== null) {
            $stored = self::metadata($twoFactor->data)['keys'];
            TwoFactorTokens::validateYubikey($verificationToken, $user->uuid, $stored, $stored !== []);
        } else {
            TwoFactorTokens::validateYubikey($verificationToken, $user->uuid, [], false);
            $twoFactor = TwoFactor::new($user->uuid, TwoFactor::TYPE_YUBIKEY, '');
        }

        foreach ($keys as $key) {
            if (strlen($key) !== 12) {
                try {
                    Yubico::verify($key);
                } catch (ApiError $error) {
                    throw ApiError::message('Invalid Yubikey OTP provided', logMessage: $error->getMessage());
                }
            }
        }

        $ids = [];
        foreach ($keys as $key) {
            // str::get(..12): none when shorter or cut within a character.
            if (strlen($key) >= 12 && (strlen($key) === 12 || (ord($key[12]) & 0xC0) !== 0x80)) {
                $ids[] = substr($key, 0, 12);
            }
        }

        $twoFactor->data = Json::encode(['keys' => $ids, 'nfc' => $nfc]);
        $twoFactor->save();
        TwoFactorController::generateRecoverCode($user);
        Event::logUser(Event::USER_UPDATED_2FA, $user->uuid, $headers->device->atype, $headers->ip);

        return Json::response(Json::sorted(['yubiKey' => [...self::keyFields($ids), 'enabled' => true, 'nfc' => $nfc]]));
    }

    /**
     * DELETE /api/two-factor/yubikey: all keys at once.
     */
    public function deleteYubikeys(Headers $headers, Request $request): EmptyResponse
    {
        $verificationToken = Body::json($request)->string('userVerificationToken');
        $user = $headers->user;

        $twoFactor = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_YUBIKEY);
        if ($twoFactor !== null) {
            TwoFactorTokens::validateYubikey($verificationToken, $user->uuid, self::metadata($twoFactor->data)['keys'], true);
            $twoFactor->deleteTwoFactor();
            Event::logUser(Event::USER_DISABLED_2FA, $user->uuid, $headers->device->atype, $headers->ip);
        }

        TwoFactorController::check2faState($user, $headers->device->atype, $headers->ip);

        return new EmptyResponse;
    }

    /**
     * validate_yubikey_login: an OTP of a registered key, which the Yubico servers accept.
     *
     * @throws ApiError
     */
    public static function validateYubikeyLogin(string $response, string $data): void
    {
        if (strlen($response) !== 44) {
            throw ApiError::message('Invalid Yubikey OTP length');
        }
        $keys = self::metadata($data)['keys'];
        if ((ord($response[12]) & 0xC0) === 0x80) {
            throw ApiError::message('Invalid Yubikey OTP');
        }
        if (! in_array(substr($response, 0, 12), $keys, true)) {
            throw ApiError::message('Given Yubikey is not registered');
        }

        try {
            Yubico::verify($response);
        } catch (ApiError $error) {
            throw ApiError::message('Failed to verify Yubikey against OTP server', logMessage: $error->getMessage());
        }
    }

    /**
     * Whether the login prompt shows the NFC option.
     *
     * @throws ApiError
     */
    public static function nfc(string $data): bool
    {
        return self::metadata($data)['nfc'];
    }

    /**
     * YubikeyMetadata, with the old capitalized names as aliases.
     *
     * @return array{keys: list<string>, nfc: bool}
     *
     * @throws ApiError
     */
    private static function metadata(string $json): array
    {
        [$ok, $value] = JsonValue::decode($json);
        if (! $ok || ! $value instanceof stdClass) {
            throw ApiError::message('Serde');
        }
        $field = static function (string $name, string $alias) use ($value): mixed {
            if (property_exists($value, $name) && property_exists($value, $alias)) {
                throw ApiError::message('Serde');
            }

            return $value->{$name} ?? $value->{$alias} ?? null;
        };
        $keys = $field('keys', 'Keys');
        $nfc = $field('nfc', 'Nfc');
        if (! is_array($keys) || ! is_bool($nfc)) {
            throw ApiError::message('Serde');
        }
        $list = [];
        foreach ($keys as $key) {
            $list[] = is_string($key) ? $key : throw ApiError::message('Serde');
        }

        return ['keys' => $list, 'nfc' => $nfc];
    }

    /**
     * jsonify_yubikeys: Key1 to Key5.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private static function keyFields(array $keys): array
    {
        $fields = [];
        foreach ($keys as $index => $key) {
            $fields['Key'.($index + 1)] = $key;
        }

        return $fields;
    }
}
