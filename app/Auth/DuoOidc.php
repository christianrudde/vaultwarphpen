<?php

namespace App\Auth;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\Controllers\TwoFactor\DuoController;
use App\Models\Event;
use App\Models\TwoFactorDuoContext;
use App\Support\FormUrlencoded;
use App\Support\HttpClient;
use App\Support\JsonValue;
use App\Support\Random;
use stdClass;

/**
 * src/api/core/two_factor/duo_oidc.rs: Duo's Universal Prompt over OIDC.
 * The login prompt sends the client to Duo with a signed request; Duo sends
 * it back with a code, which the client hands in as "<code>|<state>" and we
 * exchange for the result. The state and nonce wait in TwoFactorDuoContext;
 * the nonce Duo sees is bound to the device. Tokens are HS512 JWTs signed
 * with the client secret.
 */
final class DuoOidc
{
    private const string REDIRECT_LOCATION = 'duo-redirect-connector.html';

    private const int JWT_VALIDITY_SECS = 300;

    private const int CTX_VALIDITY_SECS = 300;

    private const int STATE_LENGTH = 64;

    /**
     * get_duo_auth_url: where the client goes for the second factor.
     *
     * @throws ApiError
     */
    public static function authUrl(string $email, string $clientId, string $deviceIdentifier): string
    {
        $keys = DuoController::keysForEmail($email);
        self::healthCheck($keys);

        $state = Random::alphanumeric(self::STATE_LENGTH);
        $nonce = Random::alphanumeric(self::STATE_LENGTH);
        try {
            TwoFactorDuoContext::store($state, $email, $nonce, self::CTX_VALIDITY_SECS);
        } catch (ApiError $error) {
            throw ApiError::message("Error saving Duo authentication context: {$error->getMessage()}");
        }

        $now = time();
        $request = self::encodeJwt([
            'response_type' => 'code',
            'scope' => 'openid',
            'exp' => $now + self::JWT_VALIDITY_SECS,
            'client_id' => $keys['ik'],
            'redirect_uri' => self::callbackUrl($clientId),
            'state' => $state,
            'duo_uname' => $email,
            'iss' => $keys['ik'],
            'aud' => "https://{$keys['host']}",
            'nonce' => self::deviceNonce($nonce, $deviceIdentifier),
        ], $keys['sk']);

        return "https://{$keys['host']}/oauth/v1/authorize?".FormUrlencoded::encode(['response_type' => 'code', 'client_id' => $keys['ik'], 'request' => $request]);
    }

    /**
     * validate_duo_login: the code Duo gave the client, for the state of a
     * context that is used up by the attempt.
     *
     * @throws ApiError
     */
    public static function validateLogin(string $email, string $token, string $clientId, string $deviceIdentifier): void
    {
        $failure = static fn (string $message): ApiError => ApiError::message($message, event: Event::USER_FAILED_LOG_IN_2FA);
        $parts = explode('|', $token);
        if (count($parts) !== 2) {
            throw $failure('Invalid response length');
        }
        [$code, $state] = $parts;

        $keys = DuoController::keysForEmail($email);
        $context = TwoFactorDuoContext::findByState($state);
        if ($context !== null) {
            try {
                $context->deleteContext();
            } catch (ApiError) {
                // Ignored like upstream.
            }
        }
        if ($context === null || $context->exp < time() || ! hash_equals($context->user_email, $email) || ! hash_equals($context->state, $state)) {
            throw $failure('Error validating duo authentication');
        }

        self::healthCheck($keys);
        try {
            self::exchangeCode($keys, self::callbackUrl($clientId), $code, $email, self::deviceNonce($context->nonce, $deviceIdentifier));
        } catch (ApiError) {
            throw $failure('Error validating duo authentication');
        }
    }

    /**
     * The "required" health check: the keys work and Duo is up.
     *
     * @param  array{host: string, ik: string, sk: string}  $keys
     *
     * @throws ApiError
     */
    private static function healthCheck(array $keys): void
    {
        $url = "https://{$keys['host']}/oauth/v1/health_check";
        $body = FormUrlencoded::encode(['client_assertion' => self::encodeJwt(self::clientAssertion($keys['ik'], $url), $keys['sk']), 'client_id' => $keys['ik']]);
        try {
            [, $response] = HttpClient::send('POST', $url, ['User-Agent' => 'vaultwarden:Duo/2.0 (Rust)', 'Content-Type' => 'application/x-www-form-urlencoded'], $body);
        } catch (ApiError $error) {
            throw ApiError::message("Error requesting Duo health check: {$error->getMessage()}");
        }

        // The untagged HealthCheckResponse: {stat} first, else {message, message_detail}.
        [$ok, $value] = JsonValue::decode($response);
        if ($ok && $value instanceof stdClass && is_string($value->stat ?? null)) {
            if ($value->stat !== 'OK') {
                throw ApiError::message("Duo health check failed, got OK-like body with stat {$value->stat}");
            }

            return;
        }
        if ($ok && $value instanceof stdClass && is_string($value->message ?? null) && is_string($value->message_detail ?? null)) {
            throw ApiError::message("Duo health check FAIL response, msg: {$value->message}, detail: {$value->message_detail}");
        }

        throw ApiError::message('Duo health check response decode error: Error { kind: Decode, source: Error("data did not match any variant of untagged enum HealthCheckResponse", line: 0, column: 0) }');
    }

    /**
     * exchange_authz_code_for_result: Duo's ID token must be for this user
     * and nonce.
     *
     * @param  array{host: string, ik: string, sk: string}  $keys
     *
     * @throws ApiError
     */
    private static function exchangeCode(array $keys, string $redirectUri, string $code, string $username, string $nonce): void
    {
        if ($code === '') {
            throw ApiError::message('Empty Duo authorization code');
        }

        $url = "https://{$keys['host']}/oauth/v1/token";
        $body = FormUrlencoded::encode([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => self::encodeJwt(self::clientAssertion($keys['ik'], $url), $keys['sk']),
        ]);
        [$status, $response] = HttpClient::send('POST', $url, ['User-Agent' => 'vaultwarden:Duo/2.0 (Rust)', 'Content-Type' => 'application/x-www-form-urlencoded'], $body);
        if ($status !== 200) {
            throw ApiError::message("Failure response from Duo: {$status}");
        }

        [$ok, $value] = JsonValue::decode($response);
        if (! $ok || ! $value instanceof stdClass || ! is_string($value->id_token ?? null) || ! is_string($value->access_token ?? null)
            || ! is_int($value->expires_in ?? null) || ! is_string($value->token_type ?? null)) {
            throw ApiError::message('Error decoding ID token response');
        }

        $claims = self::decodeJwt($value->id_token, $keys['sk'], $keys['ik'], $url);
        if (! is_string($claims['preferred_username'] ?? null) || ! is_string($claims['nonce'] ?? null)) {
            throw ApiError::message('Failed to decode Duo token');
        }
        if (! hash_equals($nonce, $claims['nonce']) || ! hash_equals($username, $claims['preferred_username'])) {
            throw ApiError::message('Error validating Duo authorization, nonce or username mismatch.');
        }
    }

    /**
     * make_callback_url: the web vault's bridge back to the client.
     */
    private static function callbackUrl(string $clientId): string
    {
        return app(Config::class)->string('domain').'/'.self::REDIRECT_LOCATION.'?'.FormUrlencoded::encode(['client' => $clientId]);
    }

    /**
     * The nonce bound to the device: SHA-512/256 of the nonce and the device id, in hex.
     */
    private static function deviceNonce(string $nonce, string $deviceIdentifier): string
    {
        return hash('sha512/256', $nonce.$deviceIdentifier);
    }

    /**
     * ClientAssertion, for the health check and the code exchange.
     *
     * @return array<string, int|string>
     */
    private static function clientAssertion(string $clientId, string $url): array
    {
        $now = time();

        return ['iss' => $clientId, 'sub' => $clientId, 'aud' => $url, 'exp' => $now + self::JWT_VALIDITY_SECS,
            'jti' => Random::alphanumeric(self::STATE_LENGTH), 'iat' => $now];
    }

    /**
     * @param  array<string, int|string>  $claims
     */
    private static function encodeJwt(array $claims, string $secret): string
    {
        $input = self::base64Url('{"typ":"JWT","alg":"HS512"}').'.'.self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $input.'.'.self::base64Url(hash_hmac('sha512', $input, $secret, true));
    }

    /**
     * jsonwebtoken's HS512 validation of Duo's ID token: exp, aud and iss
     * required, a minute of leeway.
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private static function decodeJwt(string $token, string $secret, string $audience, string $issuer): array
    {
        $failure = ApiError::message('Failed to decode Duo token');
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw $failure;
        }
        $header = json_decode((string) self::base64UrlDecode($parts[0]), true);
        $claims = json_decode((string) self::base64UrlDecode($parts[1]), true);
        $signature = self::base64UrlDecode($parts[2]);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS512' || ! is_array($claims) || $signature === null
            || ! hash_equals(hash_hmac('sha512', "{$parts[0]}.{$parts[1]}", $secret, true), $signature)) {
            throw $failure;
        }

        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (! is_int($claims['exp'] ?? null) || $claims['exp'] < time() - 60 || ! in_array($audience, $audiences, true) || ($claims['iss'] ?? null) !== $issuer) {
            throw $failure;
        }

        $result = [];
        foreach ($claims as $name => $value) {
            $result[(string) $name] = $value;
        }

        return $result;
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
