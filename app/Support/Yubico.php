<?php

namespace App\Support;

use App\Config\Config;
use App\Http\ApiError;

/**
 * yubico_ng 1.0.0: checks a YubiKey OTP with a server of the Yubico
 * Validation Protocol 2.0. The request and the answer are signed with
 * HMAC-SHA1 under the API key; the answer must echo the OTP and the nonce.
 */
final class Yubico
{
    private const string DEFAULT_SERVER = 'https://api.yubico.com/wsapi/2.0/verify';

    /**
     * get_yubico_credentials: the client id and the secret key.
     *
     * @return array{string, string}
     *
     * @throws ApiError
     */
    public static function credentials(): array
    {
        $config = app(Config::class);
        if (! $config->bool('_enable_yubico')) {
            throw ApiError::message('Yubico support is disabled');
        }
        $id = $config->optionalString('yubico_client_id');
        $secret = $config->optionalString('yubico_secret_key');
        if ($id === null || $secret === null) {
            throw ApiError::message('`YUBICO_CLIENT_ID` or `YUBICO_SECRET_KEY` environment variable is not set. Yubikey OTP Disabled');
        }

        return [$id, $secret];
    }

    /**
     * verify_yubikey_otp. The callers replace the message of any failure.
     *
     * @throws ApiError
     */
    public static function verify(string $otp): void
    {
        [$id, $secret] = self::credentials();
        $key = base64_decode($secret, true);
        if ($key === false || base64_encode($key) !== $secret) {
            throw ApiError::message('DecodeError');
        }
        if (strlen($key) !== 20) {
            throw ApiError::message('InvalidKeyLength');
        }
        if ($id === '') {
            throw ApiError::message('MissingCredentials');
        }

        // A YubiKey may be set to end the OTP with a line break.
        $otp = (string) preg_replace('/^\s+|\s+$/u', '', $otp);
        if (strlen($otp) < 32 || strlen($otp) > 48 || preg_match('/^[\x21-\x7E]*$/D', $otp) !== 1) {
            throw ApiError::message('BadOtp');
        }

        $nonce = Random::alphanumeric(40);
        // The parameters in alphabetical order, as the protocol demands.
        $query = FormUrlencoded::encode(['id' => $id, 'nonce' => $nonce, 'otp' => $otp, 'sl' => '100']);
        $query .= '&'.FormUrlencoded::encode(['h' => base64_encode(hash_hmac('sha1', $query, $key, true))]);

        $server = app(Config::class)->optionalString('yubico_server') ?? self::DEFAULT_SERVER;
        [$status, $body] = HttpClient::send('GET', "{$server}?{$query}", enforceBlock: false, redirects: false);
        if ($status < 200 || $status >= 300) {
            throw ApiError::message("HTTPStatus({$status})");
        }

        self::verifyResponse($body, $otp, $nonce, $key);
    }

    /**
     * @throws ApiError
     */
    private static function verifyResponse(string $body, string $otp, string $nonce, string $key): void
    {
        $fields = [];
        foreach (explode("\n", $body) as $line) {
            $line = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $fields[$name] = $value;
            }
        }
        ksort($fields, SORT_STRING);

        $status = $fields['status'] ?? throw ApiError::message('UnknownStatus');
        // An error status comes first: such answers are often not signed.
        if ($status !== 'OK') {
            throw ApiError::message($status);
        }

        $signature = base64_decode($fields['h'] ?? throw ApiError::message('InvalidResponse'), true);
        $signed = [];
        foreach ($fields as $name => $value) {
            if ($name !== 'h') {
                $signed[] = "{$name}={$value}";
            }
        }
        if ($signature === false || ! hash_equals(hash_hmac('sha1', implode('&', $signed), $key, true), $signature)) {
            throw ApiError::message('SignatureMismatch');
        }

        if (($fields['otp'] ?? throw ApiError::message('InvalidResponse')) !== $otp) {
            throw ApiError::message('OTPMismatch');
        }
        if (($fields['nonce'] ?? throw ApiError::message('InvalidResponse')) !== $nonce) {
            throw ApiError::message('NonceMismatch');
        }
    }
}
