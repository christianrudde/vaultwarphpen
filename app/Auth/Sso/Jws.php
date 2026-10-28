<?php

namespace App\Auth\Sso;

use stdClass;

/**
 * Signed JWTs of an OpenID provider, checked like openidconnect's JWT code
 * does: the key from the provider's JWK set (by key id and compatible
 * type), and RS*, ES*, EdDSA or, with the client secret, HS* signatures.
 * PS* signatures are not supported.
 */
final class Jws
{
    /**
     * Key type and hash of each signing algorithm.
     */
    private const array ALGORITHMS = [
        'RS256' => ['RSA', 'sha256'], 'RS384' => ['RSA', 'sha384'], 'RS512' => ['RSA', 'sha512'],
        'PS256' => ['RSA', 'sha256'], 'PS384' => ['RSA', 'sha384'], 'PS512' => ['RSA', 'sha512'],
        'ES256' => ['EC', 'sha256'], 'ES384' => ['EC', 'sha384'], 'ES512' => ['EC', 'sha512'],
        'HS256' => ['oct', 'sha256'], 'HS384' => ['oct', 'sha384'], 'HS512' => ['oct', 'sha512'],
        'EdDSA' => ['OKP', null],
    ];

    private const array CURVES = ['P-256' => '06082a8648ce3d030107', 'P-384' => '06052b81040022', 'P-521' => '06052b81040023'];

    /**
     * @param  stdClass  $header  The JOSE header
     * @param  stdClass  $payload  The claims
     */
    private function __construct(
        public readonly stdClass $header,
        public readonly stdClass $payload,
        private readonly string $signingInput,
        private readonly string $signature,
    ) {}

    /**
     * Null where the token is no compact JWS with JSON objects.
     */
    public static function parse(string $token): ?self
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode(self::base64UrlDecode($parts[0]) ?? '');
        $payload = json_decode(self::base64UrlDecode($parts[1]) ?? '');
        $signature = self::base64UrlDecode($parts[2]);
        if (! $header instanceof stdClass || ! $payload instanceof stdClass || $signature === null) {
            return null;
        }

        return new self($header, $payload, "{$parts[0]}.{$parts[1]}", $signature);
    }

    public function algorithm(): ?string
    {
        return is_string($this->header->alg ?? null) ? $this->header->alg : null;
    }

    public static function usesSharedSecret(string $algorithm): bool
    {
        return (self::ALGORITHMS[$algorithm][0] ?? null) === 'oct';
    }

    public static function isKnown(string $algorithm): bool
    {
        return isset(self::ALGORITHMS[$algorithm]);
    }

    /**
     * JsonWebKeySet::filter_keys: the keys with the token's key id, if it
     * names one, that may sign with the algorithm.
     *
     * @param  list<stdClass>  $keys
     * @return list<stdClass>
     */
    public static function filterKeys(array $keys, ?string $keyId, string $algorithm): array
    {
        $type = self::ALGORITHMS[$algorithm][0] ?? null;

        return array_values(array_filter($keys, static fn (stdClass $key): bool => ($keyId === null || ($key->kid ?? null) === $keyId)
            && (! isset($key->use) || $key->use === 'sig')
            && ($key->kty ?? null) === $type
            && (! isset($key->alg) || $key->alg === $algorithm)));
    }

    /**
     * Whether the signature is valid for the key: a JWK, or the client secret for HS*.
     */
    public function verify(string $algorithm, stdClass|string $key): bool
    {
        [$type, $hash] = self::ALGORITHMS[$algorithm] ?? [null, null];

        if ($type === 'oct') {
            return is_string($key) && is_string($hash) && hash_equals(hash_hmac($hash, $this->signingInput, $key, true), $this->signature);
        }
        if (! $key instanceof stdClass) {
            return false;
        }
        if ($type === 'OKP') {
            $x = is_string($key->x ?? null) ? self::base64UrlDecode($key->x) : null;

            return ($key->crv ?? null) === 'Ed25519' && $x !== null && strlen($x) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && strlen($this->signature) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($this->signature, $this->signingInput, $x);
        }
        if (str_starts_with($algorithm, 'PS') || ! is_string($hash)) {
            return false;
        }

        $pem = $type === 'RSA' ? self::rsaPem($key) : self::ecPem($key, $algorithm);
        $signature = $type === 'EC' ? self::ecDerSignature($this->signature) : $this->signature;
        if ($pem === null || $signature === null) {
            return false;
        }

        return openssl_verify($this->signingInput, $signature, $pem, $hash) === 1;
    }

    public static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function rsaPem(stdClass $key): ?string
    {
        $n = is_string($key->n ?? null) ? self::base64UrlDecode($key->n) : null;
        $e = is_string($key->e ?? null) ? self::base64UrlDecode($key->e) : null;
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }
        $rsaKey = self::der(0x30, self::derInteger($n).self::derInteger($e));
        $algorithm = self::der(0x30, hex2bin('06092a864886f70d0101010500') ?: '');

        return self::pem(self::der(0x30, $algorithm.self::der(0x03, "\0".$rsaKey)));
    }

    private static function ecPem(stdClass $key, string $algorithm): ?string
    {
        $curve = is_string($key->crv ?? null) ? $key->crv : '';
        $expected = ['ES256' => 'P-256', 'ES384' => 'P-384', 'ES512' => 'P-521'][$algorithm] ?? null;
        $x = is_string($key->x ?? null) ? self::base64UrlDecode($key->x) : null;
        $y = is_string($key->y ?? null) ? self::base64UrlDecode($key->y) : null;
        if ($curve !== $expected || $x === null || $y === null) {
            return null;
        }
        $algorithmId = self::der(0x30, (hex2bin('06072a8648ce3d0201') ?: '').(hex2bin(self::CURVES[$curve]) ?: ''));

        return self::pem(self::der(0x30, $algorithmId.self::der(0x03, "\0\x04".$x.$y)));
    }

    /**
     * The raw r and s of a JWS ECDSA signature as the DER sequence OpenSSL expects.
     */
    private static function ecDerSignature(string $signature): ?string
    {
        $length = strlen($signature);
        if ($length === 0 || $length % 2 !== 0) {
            return null;
        }
        $half = intdiv($length, 2);

        return self::der(0x30, self::derInteger(substr($signature, 0, $half)).self::derInteger(substr($signature, $half)));
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0".$bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag & 0xFF).chr($length & 0xFF).$content;
        }
        $lengthBytes = ltrim(pack('N', $length), "\0");

        return chr($tag & 0xFF).chr((0x80 | strlen($lengthBytes)) & 0xFF).$lengthBytes.$content;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
