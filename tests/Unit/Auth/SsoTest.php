<?php

namespace Tests\Unit\Auth;

use App\Auth\Sso\AuthenticatedUser;
use App\Auth\Sso\Jws;
use App\Auth\Sso\Sso;
use App\Http\ApiError;
use App\Support\FormUrlencoded;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The parts of the SSO login that need no provider: the signatures of its
 * tokens, the stored form of the authenticated user and the encodings.
 */
class SsoTest extends TestCase
{
    public function test_rsa_and_ec_signatures(): void
    {
        foreach ([['RS256', OPENSSL_KEYTYPE_RSA, null, 'sha256'], ['ES256', OPENSSL_KEYTYPE_EC, 'prime256v1', 'sha256'],
            ['ES384', OPENSSL_KEYTYPE_EC, 'secp384r1', 'sha384']] as [$algorithm, $type, $curve, $hash]) {
            $key = openssl_pkey_new(array_filter(['private_key_type' => $type, 'private_key_bits' => 2048, 'curve_name' => $curve]));
            $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
            [$token, $jwk] = $this->signed($algorithm, $key, $hash);

            $jws = Jws::parse($token);
            $this->assertNotNull($jws);
            $this->assertSame($algorithm, $jws->algorithm());
            $this->assertSame([$jwk], Jws::filterKeys([$jwk], 'key-1', $algorithm));
            $this->assertSame([], Jws::filterKeys([$jwk], 'other', $algorithm));
            $this->assertTrue($jws->verify($algorithm, $jwk), $algorithm);

            $tampered = Jws::parse(substr($token, 0, -4).(str_ends_with($token, 'AAAA') ? 'BBBB' : 'AAAA'));
            $this->assertFalse($tampered?->verify($algorithm, $jwk) ?? false, $algorithm);
        }
    }

    public function test_shared_secret_and_eddsa_signatures(): void
    {
        $input = $this->base64('{"alg":"HS256"}').'.'.$this->base64('{"sub":"1"}');
        $jws = Jws::parse($input.'.'.$this->base64(hash_hmac('sha256', $input, 'secret', true)));
        $this->assertNotNull($jws);
        $this->assertTrue(Jws::usesSharedSecret('HS256'));
        $this->assertTrue($jws->verify('HS256', 'secret'));
        $this->assertFalse($jws->verify('HS256', 'other'));

        $pair = sodium_crypto_sign_keypair();
        $input = $this->base64('{"alg":"EdDSA"}').'.'.$this->base64('{"sub":"1"}');
        $jws = Jws::parse($input.'.'.$this->base64(sodium_crypto_sign_detached($input, sodium_crypto_sign_secretkey($pair))));
        $this->assertNotNull($jws);
        $jwk = (object) ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $this->base64(sodium_crypto_sign_publickey($pair))];
        $this->assertTrue($jws->verify('EdDSA', $jwk));

        // Keys for encryption only, or for another algorithm, do not sign.
        $this->assertSame([], Jws::filterKeys([(object) ['kty' => 'RSA', 'use' => 'enc']], null, 'RS256'));
        $this->assertSame([], Jws::filterKeys([(object) ['kty' => 'RSA', 'alg' => 'RS512']], null, 'RS256'));
        $this->assertNull(Jws::parse('a.b'));
    }

    public function test_the_authenticated_user_is_stored_like_serde_writes_it(): void
    {
        $user = new AuthenticatedUser('refresh', 'access', 3600, 'https://idp/sub', 'judy@example.com', true, 'Judy');
        $json = '{"refresh_token":"refresh","access_token":"access","expires_in":{"secs":3600,"nanos":0},'
            .'"identifier":"https://idp/sub","email":"judy@example.com","email_verified":true,"user_name":"Judy"}';
        $this->assertSame($json, $user->toJson());
        $this->assertEquals($user, AuthenticatedUser::fromJson($json));

        $sparse = new AuthenticatedUser(null, 'access', null, 'id', 'judy@example.com', null, null);
        $this->assertEquals($sparse, AuthenticatedUser::fromJson($sparse->toJson()));
        $this->assertNull(AuthenticatedUser::fromJson('{"access_token":1}'));
    }

    public function test_the_state_is_strict_base64(): void
    {
        $this->assertSame('state', Sso::decodeState(base64_encode('state')));
        foreach (['%%' => 'Failed to decode %% using base64', 'c3RhdGU' => 'Failed to decode c3RhdGU using base64',
            'c3RhdGV=' => 'Failed to decode c3RhdGV= using base64', base64_encode("\xff") => 'Invalid utf8 chars in /w== after base64 decoding'] as $state => $message) {
            try {
                Sso::decodeState($state);
                $this->fail("{$state} decoded.");
            } catch (ApiError $error) {
                $this->assertSame($message, $error->getMessage());
            }
        }
    }

    public function test_form_encoding_of_the_redirects(): void
    {
        $this->assertSame([['a', 'b c'], ['d', ''], ['e', 'f=g']], FormUrlencoded::parse('a=b+c&&d&e=f%3Dg'));
        $this->assertSame('https://idp/authorize?x=1&state=c3%3D&scope=openid+email#top',
            FormUrlencoded::appendPairs('https://idp/authorize?x=1#top', [['state', 'c3='], ['scope', 'openid email']]));
        $this->assertSame('bitwarden://sso-callback?code=1', FormUrlencoded::appendPairs('bitwarden://sso-callback', [['code', '1']]));
    }

    /**
     * @return array{string, stdClass} The token and the JWK of its key
     */
    private function signed(string $algorithm, OpenSSLAsymmetricKey $key, string $hash): array
    {
        $input = $this->base64((string) json_encode(['alg' => $algorithm, 'kid' => 'key-1'])).'.'.$this->base64('{"sub":"1"}');
        $this->assertTrue(openssl_sign($input, $signature, $key, $hash));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        if (str_starts_with($algorithm, 'RS')) {
            $jwk = (object) ['kty' => 'RSA', 'kid' => 'key-1', 'n' => $this->part($details, 'rsa', 'n'), 'e' => $this->part($details, 'rsa', 'e')];
        } else {
            // A DER signature becomes the raw r and s of JWS.
            $size = $algorithm === 'ES256' ? 32 : 48;
            $signature = $this->rawEcSignature(is_string($signature) ? $signature : '', $size);
            $jwk = (object) ['kty' => 'EC', 'kid' => 'key-1', 'crv' => $algorithm === 'ES256' ? 'P-256' : 'P-384',
                'x' => $this->part($details, 'ec', 'x'), 'y' => $this->part($details, 'ec', 'y')];
        }

        return [$input.'.'.$this->base64(is_string($signature) ? $signature : ''), $jwk];
    }

    /**
     * A number of the key details, base64url encoded.
     *
     * @param  array<mixed>  $details
     */
    private function part(array $details, string $type, string $name): string
    {
        $value = is_array($details[$type] ?? null) ? ($details[$type][$name] ?? null) : null;
        $this->assertIsString($value);

        return $this->base64($value);
    }

    private function rawEcSignature(string $der, int $size): string
    {
        $offset = 2;
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), $size, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    private function base64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
