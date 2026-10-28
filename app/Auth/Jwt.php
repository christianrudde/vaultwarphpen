<?php

namespace App\Auth;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use RuntimeException;
use stdClass;

/**
 * Vaultwarden's tokens (auth::encode_jwt / decode_jwt): RS256, 30 seconds of
 * leeway, and the checks and messages of the jsonwebtoken crate.
 */
final readonly class Jwt
{
    public const int LEEWAY = 30;

    /**
     * Header::new(RS256) serializes these two fields in this order.
     */
    private const array HEADER = ['typ' => 'JWT', 'alg' => 'RS256'];

    public function __construct(
        private RsaKeys $keys,
        private Config $config,
    ) {}

    public function issuer(JwtIssuer $issuer): string
    {
        return $this->config->string('domain_origin').'|'.$issuer->value;
    }

    /**
     * @param  array<string, mixed>  $claims  In the field order of the Rust claims struct
     */
    public function encode(array $claims): string
    {
        $input = self::base64Url(Json::encode(self::HEADER)).'.'.self::base64Url(Json::encode($claims));

        if (! openssl_sign($input, $signature, $this->keys->privateKey(), OPENSSL_ALGO_SHA256) || ! is_string($signature)) {
            throw new RuntimeException('Error encoding jwt '.openssl_error_string());
        }

        return $input.'.'.self::base64Url($signature);
    }

    /**
     * The fields of InviteJwtClaims, which the invitation token must have.
     */
    public const array INVITE_CLAIMS = ['nbf' => 'int', 'exp' => 'int', 'iss' => 'string', 'sub' => 'string', 'email' => 'string',
        'org_id' => 'string', 'member_id' => 'string', 'invited_by_email' => '?string'];

    /**
     * @param  array<string, 'int'|'string'|'?string'>  $fields  The claims struct, in field order: jsonwebtoken
     *                                                           deserializes it before it validates the token
     * @return array<string, mixed> The claims
     *
     * @throws ApiError
     */
    public function decode(string $token, JwtIssuer $issuer, ?int $now = null, array $fields = []): array
    {
        $token = (string) preg_replace('/\s+/u', '', $token);

        // jsonwebtoken splits off the signature and the payload from the right.
        $signatureStart = strrpos($token, '.');
        $payloadStart = $signatureStart === false ? false : strrpos(substr($token, 0, $signatureStart), '.');
        if ($signatureStart === false || $payloadStart === false) {
            throw ApiError::message('Token is invalid');
        }

        $header = self::decodePart(substr($token, 0, $payloadStart));
        if (($header['alg'] ?? null) !== 'RS256') {
            throw self::error(is_string($header['alg'] ?? null) ? 'InvalidAlgorithm' : 'Json');
        }

        $signature = self::base64UrlDecode(substr($token, $signatureStart + 1));
        if (openssl_verify(substr($token, 0, $signatureStart), $signature, $this->keys->publicKey(), OPENSSL_ALGO_SHA256) !== 1) {
            throw self::error('InvalidSignature');
        }

        $payload = substr($token, $payloadStart + 1, $signatureStart - $payloadStart - 1);
        $claims = self::decodePart($payload);
        self::deserialize($claims, $fields, self::base64UrlDecode($payload));
        $this->validate($claims, $this->issuer($issuer), $now ?? time());

        return $claims;
    }

    /**
     * serde's deserialization of the claims struct: the first missing field
     * is reported where the object ends. Wrong types are reported less exactly.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, 'int'|'string'|'?string'>  $fields
     */
    private static function deserialize(array $claims, array $fields, string $json): void
    {
        foreach ($fields as $name => $type) {
            if (! array_key_exists($name, $claims)) {
                if ($type === '?string') {
                    continue;
                }
                $end = strrpos($json, '}');
                $before = $end === false ? $json : substr($json, 0, $end + 1);
                $newline = strrpos($before, "\n");
                $line = substr_count($before, "\n") + 1;
                $column = $newline === false ? strlen($before) : strlen($before) - $newline - 1;

                throw self::error("Json(Error(\"missing field `{$name}`\", line: {$line}, column: {$column}))");
            }
            $value = $claims[$name];
            $valid = match ($type) {
                'int' => is_int($value),
                'string' => is_string($value),
                '?string' => $value === null || is_string($value),
            };
            if (! $valid) {
                throw self::error('Json');
            }
        }
    }

    /**
     * jsonwebtoken's validation: required exp, then exp, nbf, iss and aud.
     *
     * @param  array<string, mixed>  $claims
     */
    private function validate(array $claims, string $issuer, int $now): void
    {
        $exp = $claims['exp'] ?? null;
        $nbf = $claims['nbf'] ?? null;

        if (! is_int($exp) || $exp < 0) {
            throw self::error('MissingRequiredClaim("exp")');
        }
        if ($exp < $now - self::LEEWAY) {
            throw ApiError::message('Token has expired');
        }
        if (is_int($nbf) && $nbf >= 0 && $nbf > $now + self::LEEWAY) {
            throw self::error('ImmatureSignature');
        }

        $iss = $claims['iss'] ?? null;
        if ((is_string($iss) && $iss !== $issuer) || (is_array($iss) && $iss !== [$issuer] && ! in_array($issuer, $iss, true))) {
            throw ApiError::message('Issuer is invalid');
        }

        if (array_key_exists('aud', $claims)) {
            throw self::error('InvalidAudience');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodePart(string $part): array
    {
        $decoded = json_decode(self::base64UrlDecode($part));
        if (! $decoded instanceof stdClass) {
            throw self::error('Json');
        }

        $values = [];
        foreach (get_object_vars($decoded) as $name => $value) {
            $values[(string) $name] = self::toArray($value);
        }

        return $values;
    }

    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::toArray(...), $value) : $value;
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 without padding, as jsonwebtoken requires it.
     */
    private static function base64UrlDecode(string $data): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]*$/D', $data) || strlen($data) % 4 === 1) {
            throw self::error('Base64');
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * Errors without a message of their own show jsonwebtoken's error kind.
     */
    private static function error(string $kind): ApiError
    {
        return ApiError::message("Error decoding JWT: Error({$kind})");
    }
}
