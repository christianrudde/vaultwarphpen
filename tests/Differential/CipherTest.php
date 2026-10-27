<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * The personal vault of src/api/core/ciphers.rs on both servers.
 */
class CipherTest extends DifferentialTestCase
{
    #[Parity('route:POST /api/ciphers')]
    #[Parity('route:GET /api/ciphers')]
    #[Parity('route:GET /api/ciphers/{cipher_id}')]
    #[Parity('route:GET /api/ciphers/{cipher_id}/details')]
    #[Parity('route:GET /api/ciphers/{cipher_id}/admin')]
    public function test_creating_and_reading_ciphers(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'ciphers@example.com');
            $folder = $server->json('POST', '/api/folders', ['name' => '2.folder'], $auth)->jsonString('id');
            $steps = [];

            $steps['login'] = $server->json('POST', '/api/ciphers', [
                ...self::cipher($userId, 1, '2.login'),
                'folderId' => $folder,
                'favorite' => true,
                'reprompt' => 1,
                'notes' => '2.notes',
                'fields' => [
                    ['Name' => '2.field', 'Value' => '2.value', 'Type' => '0', 'response' => 'drop me'],
                    ['name' => '2.hidden', 'value' => '2.secret', 'type' => 'x'],
                    ['name' => '2.boolean', 'type' => 2],
                ],
                'passwordHistory' => [
                    ['password' => '2.old', 'lastUsedDate' => '2026-01-02T03:04:05.123+02:00'],
                    ['password' => null, 'lastUsedDate' => '2026-01-02T03:04:05Z'],
                    ['Password' => '2.older'],
                ],
                'login' => [
                    'Username' => '2.user',
                    'password' => '2.pass',
                    'passwordRevisionDate' => '2026-03-04T05:06:07.1Z',
                    'totp' => null,
                    'response' => 'drop me',
                    'uris' => [
                        ['uri' => '2.first', 'match' => '3', 'response' => 'drop me'],
                        ['uri' => '2.second', 'match' => 'nonsense'],
                        ['uri' => '2.third', 'match' => null],
                    ],
                    'fido2Credentials' => [],
                ],
            ], $auth);
            $loginId = $steps['login']->jsonString('id');

            $steps['empty login'] = $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.empty'), 'login' => (object) []], $auth);
            $steps['secure note'] = $server->json('POST', '/api/ciphers', [...self::cipher($userId, 2, '2.note'), 'secureNote' => ['type' => 0]], $auth);
            $steps['broken note'] = $server->json('POST', '/api/ciphers', [...self::cipher($userId, 2, '2.broken'), 'secureNote' => ['type' => 'x']], $auth);
            $steps['card'] = $server->json('POST', '/api/ciphers', [...self::cipher($userId, 3, '2.card'),
                'card' => ['cardholderName' => '2.h', 'number' => '2.n', 'expMonth' => '2.m', 'expYear' => '2.y', 'code' => '2.c', 'brand' => '2.b']], $auth);
            $steps['identity'] = $server->json('POST', '/api/ciphers', [...self::cipher($userId, 4, '2.identity'),
                'identity' => ['FirstName' => '2.f', 'SSN' => '2.s', 'Address1' => '2.a']], $auth);
            $steps['incomplete ssh key'] = $server->json('POST', '/api/ciphers', [...self::cipher($userId, 5, '2.ssh'),
                'sshKey' => ['privateKey' => '2.p', 'publicKey' => '', 'keyFingerprint' => '2.f']], $auth);

            $steps['get'] = $server->send('GET', "/api/ciphers/{$loginId}", $auth);
            $steps['details'] = $server->send('GET', "/api/ciphers/{$loginId}/details", $auth);
            $steps['admin'] = $server->send('GET', "/api/ciphers/{$loginId}/admin", $auth);
            $list = $server->send('GET', '/api/ciphers', $auth);
            $steps['list'] = $list->withBody(self::sortedByName($list->body));
            $steps['unknown'] = $server->send('GET', '/api/ciphers/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $auth);
            $steps['invalid id'] = $server->send('GET', '/api/ciphers/not-a-uuid', $auth);

            return $steps;
        });
    }

    public function test_invalid_ciphers(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'invalid-ciphers@example.com');
            $login = ['login' => ['username' => '2.u']];

            return [
                'other user' => $server->json('POST', '/api/ciphers', [...self::cipher('11111111-2222-4333-8444-555555555555', 1, '2.x'), ...$login], $auth),
                'no encrypted for' => $server->json('POST', '/api/ciphers', ['type' => 1, 'name' => '2.x', ...$login], $auth),
                'invalid type' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 9, '2.x'), ...$login], $auth),
                'data missing' => $server->json('POST', '/api/ciphers', self::cipher($userId, 1, '2.x'), $auth),
                'long notes' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login, 'notes' => str_repeat('n', 10001)], $auth),
                'unknown folder' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login, 'folderId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'], $auth),
                'organization' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login, 'organizationId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'], $auth),
                'string type' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login, 'type' => '1'], $auth),
                'unauthenticated' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login], ['Accept' => 'application/json']),
                'login list' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), 'login' => []], $auth),
                'card list' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 3, '2.x'), 'card' => ['2.a']], $auth),
                'scalar note' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 2, '2.x'), 'secureNote' => 5], $auth),
            ];
        });
    }

    #[Parity('route:PUT /api/ciphers/{cipher_id}')]
    #[Parity('route:POST /api/ciphers/{cipher_id}')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/admin')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/admin')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/partial')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/partial')]
    public function test_updating_ciphers(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'updates@example.com');
            $folder = $server->json('POST', '/api/folders', ['name' => '2.folder'], $auth)->jsonString('id');
            $created = $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.original'), 'login' => ['username' => '2.u']], $auth);
            $id = $created->jsonString('id');
            $revision = $created->jsonString('revisionDate');
            $steps = [];

            $steps['update'] = $server->json('PUT', "/api/ciphers/{$id}", [...self::cipher($userId, 1, '2.updated'),
                'login' => ['username' => '2.v'], 'lastKnownRevisionDate' => $revision, 'folderId' => $folder, 'favorite' => true], $auth);
            $steps['stale'] = $server->json('PUT', "/api/ciphers/{$id}", [...self::cipher($userId, 1, '2.stale'),
                'login' => ['username' => '2.w'], 'lastKnownRevisionDate' => '2020-01-01T00:00:00.000Z'], $auth);
            $steps['mobile revision'] = $server->json('POST', "/api/ciphers/{$id}", [...self::cipher($userId, 1, '2.mobile'),
                'login' => ['username' => '2.x'], 'lastKnownRevisionDate' => '0001-01-01T00:00:00'], $auth);
            $steps['admin update'] = $server->json('PUT', "/api/ciphers/{$id}/admin", [...self::cipher($userId, 1, '2.admin'), 'login' => ['username' => '2.y'],
                'archivedDate' => '2026-05-06T07:08:09.123456Z'], $auth);
            $steps['post admin update'] = $server->json('POST', "/api/ciphers/{$id}/admin", [...self::cipher($userId, 1, '2.admin2'), 'login' => ['username' => '2.z']], $auth);
            $steps['partial'] = $server->json('PUT', "/api/ciphers/{$id}/partial", ['folderId' => '', 'favorite' => false], $auth);
            $steps['partial into folder'] = $server->json('POST', "/api/ciphers/{$id}/partial", ['folderId' => $folder, 'favorite' => true], $auth);
            $steps['partial without favorite'] = $server->json('POST', "/api/ciphers/{$id}/partial", ['folderId' => $folder], $auth);
            $steps['partial unknown folder'] = $server->json('POST', "/api/ciphers/{$id}/partial", ['folderId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'favorite' => true], $auth);
            $steps['update unknown'] = $server->json('PUT', '/api/ciphers/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', [...self::cipher($userId, 1, '2.x'), 'login' => (object) []], $auth);
            $steps['after all'] = $server->send('GET', "/api/ciphers/{$id}", $auth);

            return $steps;
        });
    }

    /**
     * @return array{array<string, string>, string} The headers and the user id
     */
    public static function session(Server $server, string $email): array
    {
        $auth = FolderTest::session($server, $email);

        return [$auth, $server->send('GET', '/api/accounts/profile', $auth)->jsonString('id')];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cipher(string $userId, int $type, string $name): array
    {
        return ['type' => $type, 'name' => $name, 'encryptedFor' => $userId, 'key' => '2.cipher-key'];
    }

    /**
     * A list response with its ciphers in name order; the servers store them in their own order.
     */
    public static function sortedByName(string $body): string
    {
        $list = json_decode($body, true);
        if (! is_array($list) || ! is_array($list['data'] ?? null)) {
            return $body;
        }

        usort($list['data'], static fn (mixed $a, mixed $b): int => strcmp(
            is_array($a) && is_string($a['name'] ?? null) ? $a['name'] : '',
            is_array($b) && is_string($b['name'] ?? null) ? $b['name'] : '',
        ));

        return (string) json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
