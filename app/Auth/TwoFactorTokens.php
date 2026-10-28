<?php

namespace App\Auth;

use App\Http\ApiError;

/**
 * auth::two_factor: the user verification tokens the two-factor settings
 * hand out with their current state, valid for five minutes. Changing a
 * method needs the token issued for it, for the same user and state.
 *
 * TwoFactorClaims<T> holds the claims of one method in `claims`, a struct
 * that denies unknown fields, so a token of another method does not parse.
 */
final class TwoFactorTokens
{
    /**
     * @throws ApiError
     */
    public static function authenticator(string $userUuid, string $key, bool $enabled): string
    {
        return self::token($userUuid, $enabled, ['authenticator_key' => $key]);
    }

    /**
     * @throws ApiError
     */
    public static function validateAuthenticator(string $token, string $userUuid, string $key, bool $enabled): void
    {
        $claims = self::validate($token, $userUuid, $enabled, 'authenticator_key', required: true);
        if ($claims['authenticator_key'] !== $key) {
            throw ApiError::message('Invalid verification token: Invalid key');
        }
    }

    /**
     * @param  array{host: string, ik: string, sk: string}|null  $data
     */
    public static function duo(string $userUuid, ?array $data, bool $enabled): string
    {
        return self::token($userUuid, $enabled, ['duo_data' => $data]);
    }

    /**
     * When disabling, the token must be for the data of the method.
     *
     * @param  array{host: string, ik: string, sk: string}|null  $data
     *
     * @throws ApiError
     */
    public static function validateDuo(string $token, string $userUuid, ?array $data, bool $enabled): void
    {
        $claims = self::validate($token, $userUuid, $enabled, 'duo_data', required: false);
        $issued = $claims['duo_data'] ?? null;
        if ($issued !== null && (! is_array($issued) || ! is_string($issued['host'] ?? null) || ! is_string($issued['ik'] ?? null) || ! is_string($issued['sk'] ?? null))) {
            throw self::decodeError('Json');
        }
        if ($enabled && ($issued === null ? null : ['host' => $issued['host'], 'ik' => $issued['ik'], 'sk' => $issued['sk']]) !== $data) {
            throw ApiError::message('Invalid verification token: Invalid duo data');
        }
    }

    public static function email(string $userUuid, ?string $email, bool $enabled): string
    {
        return self::token($userUuid, $enabled, ['email' => $email]);
    }

    /**
     * When disabling, the token must be for the address of the method.
     *
     * @throws ApiError
     */
    public static function validateEmail(string $token, string $userUuid, string $email, bool $enabled): void
    {
        $claims = self::validate($token, $userUuid, $enabled, 'email', required: false);
        $issued = $claims['email'] ?? null;
        if ($issued !== null && ! is_string($issued)) {
            throw self::decodeError('Json');
        }
        if ($enabled && $issued !== $email) {
            throw ApiError::message('Invalid verification token: Invalid email');
        }
    }

    /**
     * @param  list<int>  $keys
     */
    public static function webauthn(string $userUuid, array $keys, bool $enabled): string
    {
        return self::token($userUuid, $enabled, ['webauthn_keys' => $keys]);
    }

    /**
     * @param  list<int>  $keys
     *
     * @throws ApiError
     */
    public static function validateWebauthn(string $token, string $userUuid, array $keys, bool $enabled): void
    {
        $claims = self::validate($token, $userUuid, $enabled, 'webauthn_keys', required: true);
        if ($claims['webauthn_keys'] !== $keys) {
            throw ApiError::message('Invalid verification token: Invalid keys');
        }
    }

    /**
     * @param  list<string>  $keys
     */
    public static function yubikey(string $userUuid, array $keys, bool $enabled): string
    {
        return self::token($userUuid, $enabled, ['yubi_keys' => $keys]);
    }

    /**
     * @param  list<string>  $keys
     *
     * @throws ApiError
     */
    public static function validateYubikey(string $token, string $userUuid, array $keys, bool $enabled): void
    {
        $claims = self::validate($token, $userUuid, $enabled, 'yubi_keys', required: true);
        if ($claims['yubi_keys'] !== $keys) {
            throw ApiError::message('Invalid verification token: Invalid keys');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function token(string $userUuid, bool $enabled, array $claims): string
    {
        $jwt = app(Jwt::class);
        $now = time();

        return $jwt->encode([
            'nbf' => $now,
            'exp' => $now + 300,
            'iss' => $jwt->issuer(JwtIssuer::TwoFactorAuth),
            'sub' => $userUuid,
            'enabled' => $enabled,
            'claims' => $claims,
        ]);
    }

    /**
     * The claims of the one field the method's struct has.
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private static function validate(string $token, string $userUuid, bool $enabled, string $field, bool $required): array
    {
        try {
            $claims = app(Jwt::class)->decode($token, JwtIssuer::TwoFactorAuth, fields: ['nbf' => 'int', 'exp' => 'int', 'iss' => 'string', 'sub' => 'string', 'enabled' => 'bool'],
                nested: ['claims', $field, $required]);
        } catch (ApiError $error) {
            throw ApiError::message("Failed to decode verification token: {$error->displayText()}");
        }

        if ($claims['sub'] !== $userUuid) {
            throw ApiError::message('Invalid verification token: Invalid user');
        }
        if ($claims['enabled'] !== $enabled) {
            throw ApiError::message('Invalid verification token: Invalid state');
        }
        $inner = $claims['claims'] ?? null;
        $result = [];
        foreach (is_array($inner) ? $inner : [] as $name => $value) {
            $result[(string) $name] = $value;
        }

        return $result;
    }

    private static function decodeError(string $kind): ApiError
    {
        return ApiError::message('Failed to decode verification token: '.ApiError::message("Error decoding JWT: Error({$kind})")->displayText());
    }
}
