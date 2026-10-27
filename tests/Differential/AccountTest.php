<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * Profile, keys and revision date on both servers.
 */
class AccountTest extends DifferentialTestCase
{
    #[Parity('route:GET /api/accounts/profile')]
    #[Parity('route:PUT /api/accounts/profile')]
    #[Parity('route:POST /api/accounts/profile')]
    #[Parity('route:PUT /api/accounts/avatar')]
    #[Parity('route:GET /api/users/{user_id}/public-key')]
    #[Parity('route:GET /api/accounts/keys')]
    #[Parity('route:POST /api/accounts/keys')]
    #[Parity('route:GET /api/accounts/revision-date')]
    public function test_profile_and_keys(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            $steps = LoginTest::register($server, 'profile@example.com');
            $login = $server->form('/identity/connect/token', LoginTest::loginFields('profile@example.com'));
            $auth = ['Authorization' => 'Bearer '.$login->jsonString('access_token'), 'Accept' => 'application/json'];
            $userId = $server->send('GET', '/api/accounts/profile', $auth)->jsonString('id');

            $steps['profile'] = $server->send('GET', '/api/accounts/profile', $auth);
            $steps['rename'] = $server->json('PUT', '/api/accounts/profile', ['name' => 'Profile Person', 'culture' => 'de-DE'], $auth);
            $steps['rename by post'] = $server->json('POST', '/api/accounts/profile', ['name' => 'Profile Poster'], $auth);
            $steps['too long name'] = $server->json('POST', '/api/accounts/profile', ['name' => str_repeat('x', 51)], $auth);
            $steps['no name'] = $server->json('POST', '/api/accounts/profile', [], $auth);
            $steps['avatar'] = $server->json('PUT', '/api/accounts/avatar', ['avatarColor' => '#a1b2c3'], $auth);
            $steps['short avatar'] = $server->json('PUT', '/api/accounts/avatar', ['avatarColor' => '#abc'], $auth);
            $steps['no avatar'] = $server->json('PUT', '/api/accounts/avatar', ['avatarColor' => null], $auth);
            $steps['keys'] = $server->send('GET', '/api/accounts/keys', $auth);
            $steps['keys again'] = $server->json('POST', '/api/accounts/keys', ['encryptedPrivateKey' => 'p', 'publicKey' => 'q'], $auth);
            $steps['own public key'] = $server->send('GET', "/api/users/{$userId}/public-key", $auth);
            $steps['unknown public key'] = $server->send('GET', '/api/users/00000000-1111-4222-8333-444444444444/public-key', $auth);
            $steps['invalid user id'] = $server->send('GET', '/api/users/not-a-uuid/public-key', $auth);
            $revision = $server->send('GET', '/api/accounts/revision-date', $auth);
            $steps['revision date'] = $revision->withBody(is_int($revision->json()) ? '<millis>' : $revision->body);

            return $steps;
        });
    }

    public function test_keys_of_a_user_without_key_pair(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            $token = $server->json('POST', '/identity/accounts/register/send-verification-email', ['email' => 'nokeys@example.com'], ['Accept' => 'application/json']);
            $server->json('POST', '/identity/accounts/register/finish', ['email' => 'nokeys@example.com', 'emailVerificationToken' => $token->json(),
                'kdf' => 0, 'kdfIterations' => 600000, 'masterPasswordHash' => 'client-hash', 'key' => 'user-key']);
            $login = $server->form('/identity/connect/token', LoginTest::loginFields('nokeys@example.com'));
            $auth = ['Authorization' => 'Bearer '.$login->jsonString('access_token')];

            return [
                'keys' => $server->send('GET', '/api/accounts/keys', $auth),
                'set keys' => $server->json('POST', '/api/accounts/keys', ['encryptedPrivateKey' => 'private', 'publicKey' => 'public'], $auth),
                'keys afterwards' => $server->send('GET', '/api/accounts/keys', $auth),
            ];
        });
    }

    public function test_guards_come_before_bodies(): void
    {
        $this->assertSameResponses('GET', '/api/accounts/profile', ['Accept' => 'application/json']);
        $this->assertSameResponses('GET', '/api/accounts/profile', ['Authorization' => 'Bearer nonsense', 'Accept' => 'application/json']);
        $this->assertSameResponses('POST', '/api/accounts/profile', ['Accept' => 'application/json'], '{broken');
        $this->assertSameResponses('GET', '/api/users/not-a-uuid/public-key', ['Accept' => 'application/json']);
        $this->assertSameResponses('GET', '/api/accounts/revision-date');
    }
}
