<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * POST /identity/connect/token on both servers.
 */
#[Parity('route:POST /identity/connect/token')]
class LoginTest extends DifferentialTestCase
{
    public const string DEVICE = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    public function test_password_and_refresh_logins(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            $steps = self::register($server, 'login@example.com');

            $login = self::loginFields('login@example.com');
            $steps['wrong password'] = $server->form('/identity/connect/token', [...$login, 'password' => 'wrong']);
            $steps['unknown user'] = $server->form('/identity/connect/token', [...$login, 'username' => 'nobody@example.com']);
            $steps['wrong scope'] = $server->form('/identity/connect/token', [...$login, 'scope' => 'api']);
            $steps['escaped scope'] = $server->form('/identity/connect/token', [...$login, 'scope' => "api'\x01"]);
            $steps['login'] = $server->form('/identity/connect/token', $login);
            $refreshToken = $steps['login']->jsonString('refresh_token');

            $steps['login again'] = $server->form('/identity/connect/token', [...$login, 'ClientId' => 'web', 'client_id' => 'web']);
            $steps['refresh'] = $server->form('/identity/connect/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => 'cli']);
            $steps['invalid refresh'] = $server->form('/identity/connect/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'nonsense']);

            return $steps;
        });
    }

    public function test_malformed_token_requests(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            $steps = [];
            foreach (['client_id', 'password', 'scope', 'username', 'device_identifier', 'device_name', 'device_type'] as $missing) {
                $fields = self::loginFields('nobody@example.com');
                unset($fields[$missing]);
                $steps["without {$missing}"] = $server->form('/identity/connect/token', $fields);
            }
            $steps['no grant type'] = $server->form('/identity/connect/token', ['scope' => 'api'], ['Accept' => 'application/json']);
            $steps['unknown grant type'] = $server->form('/identity/connect/token', ['grant_type' => 'magic']);
            $steps['refresh without token'] = $server->form('/identity/connect/token', ['grant_type' => 'refresh_token']);
            $steps['sso'] = $server->form('/identity/connect/token', ['grant_type' => 'authorization_code', 'client_id' => 'web', 'code' => 'x', 'code_verifier' => 'y']);
            $steps['provider not a number'] = $server->form('/identity/connect/token', [...self::loginFields('nobody@example.com'), 'two_factor_provider' => 'x'], ['Accept' => 'application/json']);
            $steps['json body'] = $server->json('POST', '/identity/connect/token', ['grant_type' => 'password'], ['Accept' => 'application/json']);
            $steps['api key with wrong scope'] = $server->form('/identity/connect/token', ['grant_type' => 'client_credentials', 'client_id' => 'user.x', 'client_secret' => 's', 'scope' => 'other']);
            $steps['malformed api key client'] = $server->form('/identity/connect/token', ['grant_type' => 'client_credentials', 'client_id' => 'x', 'client_secret' => 's',
                'scope' => 'api', 'device_identifier' => self::DEVICE, 'device_name' => 'cli', 'device_type' => '25']);

            return $steps;
        });
    }

    /**
     * @return array<string, Exchange>
     */
    public static function register(Server $server, string $email): array
    {
        $steps = [];
        $steps['verification token'] = $server->json('POST', '/identity/accounts/register/send-verification-email', ['email' => $email], ['Accept' => 'application/json']);
        $steps['register'] = $server->json('POST', '/identity/accounts/register/finish', [
            'email' => $email,
            'emailVerificationToken' => $steps['verification token']->json(),
            'kdf' => 0,
            'kdfIterations' => 600000,
            'masterPasswordHash' => 'client-hash',
            'key' => 'user-key',
            'keys' => ['encryptedPrivateKey' => 'private-key', 'publicKey' => 'public-key'],
        ]);

        return $steps;
    }

    /**
     * @return array<string, string>
     */
    public static function loginFields(string $email, string $password = 'client-hash'): array
    {
        return [
            'grant_type' => 'password',
            'username' => $email,
            'password' => $password,
            'scope' => 'api offline_access',
            'client_id' => 'cli',
            'device_identifier' => self::DEVICE,
            'device_name' => 'linux',
            'device_type' => '25',
        ];
    }
}
