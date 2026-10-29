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
                'fields with a list' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login, 'fields' => [['name' => '2.a'], []]], $auth),
                'empty field' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login, 'fields' => [(object) [], ['type' => true]]], $auth),
                'history with a list' => $server->json('POST', '/api/ciphers', [...self::cipher($userId, 1, '2.x'), ...$login, 'passwordHistory' => [['password' => '2.p'], [1]]], $auth),
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
            $steps['update into an organization'] = $server->json('PUT', "/api/ciphers/{$id}", [...self::cipher($userId, 1, '2.moved'), 'login' => (object) [],
                'organizationId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'], $auth);
            $steps['update unknown'] = $server->json('PUT', '/api/ciphers/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', [...self::cipher($userId, 1, '2.x'), 'login' => (object) []], $auth);
            $steps['after all'] = $server->send('GET', "/api/ciphers/{$id}", $auth);

            return $steps;
        });
    }

    #[Parity('route:PUT /api/ciphers/{cipher_id}/delete')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/delete-admin')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/delete')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/delete-admin')]
    #[Parity('route:DELETE /api/ciphers/{cipher_id}')]
    #[Parity('route:DELETE /api/ciphers/{cipher_id}/admin')]
    #[Parity('route:PUT /api/ciphers/delete')]
    #[Parity('route:PUT /api/ciphers/delete-admin')]
    #[Parity('route:POST /api/ciphers/delete')]
    #[Parity('route:POST /api/ciphers/delete-admin')]
    #[Parity('route:DELETE /api/ciphers')]
    #[Parity('route:DELETE /api/ciphers/admin')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/restore')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/restore-admin')]
    #[Parity('route:PUT /api/ciphers/restore')]
    #[Parity('route:PUT /api/ciphers/restore-admin')]
    public function test_trash_and_deletion(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'trash@example.com');
            $ids = [];
            foreach (['2.a', '2.b', '2.c', '2.d', '2.e', '2.f', '2.g'] as $name) {
                $ids[$name] = self::note($server, $auth, $userId, $name);
            }
            $unknown = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
            $steps = [];

            $steps['soft delete'] = $server->send('PUT', "/api/ciphers/{$ids['2.a']}/delete", $auth);
            $steps['soft delete admin'] = $server->send('PUT', "/api/ciphers/{$ids['2.b']}/delete-admin", $auth);
            $steps['in the trash'] = $server->send('GET', "/api/ciphers/{$ids['2.a']}", $auth);
            $steps['restore'] = $server->send('PUT', "/api/ciphers/{$ids['2.a']}/restore", $auth);
            $steps['restore admin'] = $server->send('PUT', "/api/ciphers/{$ids['2.b']}/restore-admin", $auth);
            $steps['bulk soft delete'] = $server->json('PUT', '/api/ciphers/delete', ['ids' => [$ids['2.a'], $ids['2.b']]], $auth);
            $steps['bulk soft delete admin'] = $server->json('PUT', '/api/ciphers/delete-admin', ['ids' => [$ids['2.c']]], $auth);
            $steps['bulk restore twice the same'] = $server->json('PUT', '/api/ciphers/restore', ['ids' => [$ids['2.a'], $ids['2.a']]], $auth);
            $restored = $server->json('PUT', '/api/ciphers/restore-admin', ['ids' => [$ids['2.b'], $ids['2.c']]], $auth);
            $steps['bulk restore admin'] = $restored->withBody(self::sortedByName($restored->body));
            $steps['bulk restore unknown'] = $server->json('PUT', '/api/ciphers/restore', ['ids' => [$unknown]], $auth);

            $steps['delete'] = $server->send('DELETE', "/api/ciphers/{$ids['2.a']}", $auth);
            $steps['delete admin'] = $server->send('DELETE', "/api/ciphers/{$ids['2.b']}/admin", $auth);
            $steps['post delete'] = $server->send('POST', "/api/ciphers/{$ids['2.c']}/delete", $auth);
            $steps['post delete admin'] = $server->send('POST', "/api/ciphers/{$ids['2.d']}/delete-admin", $auth);
            $steps['deleted'] = $server->send('GET', "/api/ciphers/{$ids['2.a']}", $auth);
            $steps['delete unknown'] = $server->send('DELETE', "/api/ciphers/{$unknown}", $auth);
            $steps['bulk delete'] = $server->json('DELETE', '/api/ciphers', ['ids' => [$ids['2.e']]], $auth);
            $steps['bulk delete admin'] = $server->json('DELETE', '/api/ciphers/admin', ['ids' => [$ids['2.f']]], $auth);
            $steps['post bulk delete'] = $server->json('POST', '/api/ciphers/delete', ['ids' => [$ids['2.g']]], $auth);
            $steps['post bulk delete admin'] = $server->json('POST', '/api/ciphers/delete-admin', ['ids' => [$unknown]], $auth);
            $steps['bulk delete without ids'] = $server->json('DELETE', '/api/ciphers', ['id' => [$unknown]], $auth);
            $steps['bulk delete with numbers'] = $server->json('DELETE', '/api/ciphers', ['ids' => [1]], $auth);
            $steps['bulk delete broken json'] = $server->send('DELETE', '/api/ciphers', [...$auth, 'Content-Type' => 'application/json'], '{"ids":');
            $steps['soft delete invalid id'] = $server->send('PUT', '/api/ciphers/not-a-uuid/delete', $auth);
            $steps['empty'] = $server->send('GET', '/api/ciphers', $auth);

            return $steps;
        });
    }

    #[Parity('route:POST /api/ciphers/move')]
    #[Parity('route:PUT /api/ciphers/move')]
    public function test_moving_ciphers(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'move@example.com');
            $folder = $server->json('POST', '/api/folders', ['name' => '2.folder'], $auth)->jsonString('id');
            $first = self::note($server, $auth, $userId, '2.first');
            self::note($server, $auth, $userId, '2.second');
            $steps = [];

            $steps['move'] = $server->json('POST', '/api/ciphers/move', ['folderId' => $folder, 'ids' => [$first]], $auth);
            $steps['moved'] = $server->send('GET', "/api/ciphers/{$first}", $auth);
            $steps['unknown folder'] = $server->json('PUT', '/api/ciphers/move', ['folderId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'ids' => [$first]], $auth);
            $steps['partly unknown'] = $server->json('PUT', '/api/ciphers/move', ['folderId' => '', 'ids' => [$first, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']], $auth);
            $steps['twice the same'] = $server->json('PUT', '/api/ciphers/move', ['folderId' => $folder, 'ids' => [$first, $first]], $auth);
            $steps['no ids'] = $server->json('POST', '/api/ciphers/move', ['ids' => []], $auth);
            $steps['without ids'] = $server->json('POST', '/api/ciphers/move', ['folderId' => $folder], $auth);
            $list = $server->send('GET', '/api/ciphers', $auth);
            $steps['list'] = $list->withBody(self::sortedByName($list->body));

            return $steps;
        });
    }

    #[Parity('route:PUT /api/ciphers/{cipher_id}/archive')]
    #[Parity('route:PUT /api/ciphers/archive')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/unarchive')]
    #[Parity('route:PUT /api/ciphers/unarchive')]
    public function test_archiving_ciphers(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'archive@example.com');
            $first = self::note($server, $auth, $userId, '2.first');
            $second = self::note($server, $auth, $userId, '2.second');
            $steps = [];

            $steps['archive'] = $server->send('PUT', "/api/ciphers/{$first}/archive", $auth);
            $steps['bulk archive'] = $server->json('PUT', '/api/ciphers/archive', ['ids' => [$second, $second]], $auth);
            $steps['unarchive'] = $server->send('PUT', "/api/ciphers/{$first}/unarchive", $auth);
            $steps['bulk unarchive'] = $server->json('PUT', '/api/ciphers/unarchive', ['ids' => [$second]], $auth);
            $steps['unarchive again'] = $server->send('PUT', "/api/ciphers/{$first}/unarchive", $auth);
            $steps['archive unknown'] = $server->send('PUT', '/api/ciphers/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee/archive', $auth);
            $steps['bulk archive unknown'] = $server->json('PUT', '/api/ciphers/archive', ['ids' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']], $auth);

            return $steps;
        });
    }

    #[Parity('route:POST /api/ciphers/purge#purge_personal_vault')]
    public function test_purging_the_personal_vault(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'purge@example.com');
            $server->json('POST', '/api/folders', ['name' => '2.folder'], $auth);
            self::note($server, $auth, $userId, '2.first');
            $steps = [];

            $steps['wrong password'] = $server->json('POST', '/api/ciphers/purge', ['masterPasswordHash' => 'wrong'], $auth);
            $steps['nothing'] = $server->send('POST', '/api/ciphers/purge', [...$auth, 'Content-Type' => 'application/json'], '{}');
            $steps['both'] = $server->json('POST', '/api/ciphers/purge', ['masterPasswordHash' => 'client-hash', 'otp' => '1'], $auth);
            $steps['no code sent'] = $server->json('POST', '/api/ciphers/purge', ['otp' => '123456'], $auth);
            $steps['an array'] = $server->json('POST', '/api/ciphers/purge', [], $auth);
            $steps['organization of others'] = $server->json('POST', '/api/ciphers/purge?organizationId=aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', ['masterPasswordHash' => 'client-hash'], $auth);
            $steps['invalid organization'] = $server->json('POST', '/api/ciphers/purge?organizationId=nope', ['masterPasswordHash' => 'client-hash'], $auth);
            $steps['still there'] = $server->send('GET', '/api/ciphers', $auth);
            $steps['purge'] = $server->json('POST', '/api/ciphers/purge', ['MasterPasswordHash' => 'client-hash'], $auth);
            $steps['ciphers'] = $server->send('GET', '/api/ciphers', $auth);
            $steps['folders'] = $server->send('GET', '/api/folders', $auth);

            return $steps;
        });
    }

    #[Parity('route:POST /api/ciphers/create')]
    #[Parity('route:POST /api/ciphers/admin')]
    public function test_creating_through_the_share_route(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'create@example.com');
            $note = [...self::cipher($userId, 2, '2.created'), 'secureNote' => ['type' => 0]];
            $unknown = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
            $steps = [];

            $steps['create'] = $server->json('POST', '/api/ciphers/create', ['cipher' => $note, 'collectionIds' => []], $auth);
            $steps['admin with old names'] = $server->json('POST', '/api/ciphers/admin', ['Cipher' => [...$note, 'name' => '2.admin'], 'CollectionIds' => []], $auth);
            $steps['clone with a revision'] = $server->json('POST', '/api/ciphers/create', ['cipher' => [...$note, 'name' => '2.clone', 'lastKnownRevisionDate' => '2000-01-01T00:00:00Z'], 'collectionIds' => []], $auth);
            $steps['data missing'] = $server->json('POST', '/api/ciphers/create', ['cipher' => self::cipher($userId, 2, '2.broken'), 'collectionIds' => []], $auth);
            $steps['unknown collection'] = $server->json('POST', '/api/ciphers/create', ['cipher' => [...$note, 'organizationId' => $unknown], 'collectionIds' => [$unknown]], $auth);
            $steps['organization of others'] = $server->json('POST', '/api/ciphers/create', ['cipher' => [...$note, 'organizationId' => $unknown], 'collectionIds' => []], $auth);
            $steps['other user'] = $server->json('POST', '/api/ciphers/create', ['cipher' => [...$note, 'encryptedFor' => $unknown], 'collectionIds' => []], $auth);
            $steps['no collection ids'] = $server->json('POST', '/api/ciphers/create', ['cipher' => $note], $auth);
            $steps['both names'] = $server->json('POST', '/api/ciphers/create', ['cipher' => $note, 'Cipher' => $note, 'collectionIds' => []], $auth);
            $list = $server->send('GET', '/api/ciphers', $auth);
            $steps['list'] = $list->withBody(self::sortedByName($list->body));

            return $steps;
        });
    }

    #[Parity('route:POST /api/ciphers/import')]
    public function test_import(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = self::session($server, 'import@example.com');
            $existing = $server->json('POST', '/api/folders', ['name' => '2.existing'], $auth)->jsonString('id');
            $note = static fn (string $name): array => [...self::cipher($userId, 2, $name), 'secureNote' => ['type' => 0]];
            $steps = [];

            $steps['import'] = $server->json('POST', '/api/ciphers/import', [
                'folders' => [['name' => '2.new', 'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'], ['name' => '2.ignored', 'id' => $existing], ['name' => '2.second', 'id' => '']],
                'folderRelationships' => [['key' => 0, 'value' => 0], ['key' => 1, 'value' => 2], ['key' => 1, 'value' => 1], ['key' => 2, 'value' => 9]],
                'ciphers' => [
                    [...$note('2.a'), 'folderId' => $existing, 'favorite' => true],
                    $note('2.b'),
                    [...self::cipher($userId, 1, '2.c'), 'login' => ['username' => '2.u'], 'lastKnownRevisionDate' => '2000-01-01T00:00:00Z'],
                    [...$note('2.d'), 'encryptedFor' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
                    [...$note('2.e'), 'organizationId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
                ],
            ], $auth);
            $folders = $server->send('GET', '/api/folders', $auth);
            $steps['folders'] = $folders->withBody(self::sortedByName($folders->body));
            $list = $server->send('GET', '/api/ciphers', $auth);
            $steps['ciphers'] = $list->withBody(self::sortedByName($list->body));

            $steps['validation'] = $server->json('POST', '/api/ciphers/import', ['folders' => [['name' => '2.f']], 'folderRelationships' => [], 'ciphers' => [
                $note('2.ok'),
                [...$note('2.long'), 'notes' => str_repeat('n', 10001)],
                [...$note('2.null'), 'passwordHistory' => [['password' => '2.p'], ['password' => null], ['password' => 5]]],
                [...$note('2.both'), 'notes' => str_repeat('n', 10001), 'passwordHistory' => [['password' => null]]],
            ]], $auth);
            $steps['invalid type stops'] = $server->json('POST', '/api/ciphers/import', ['folders' => [], 'folderRelationships' => [], 'ciphers' => [
                $note('2.first'), [...$note('2.invalid'), 'type' => 9], $note('2.never'),
            ]], $auth);
            $steps['organization'] = $server->json('POST', '/api/ciphers/import', ['folders' => [], 'folderRelationships' => [], 'ciphers' => [
                [...$note('2.org'), 'organizationId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
            ]], $auth);
            $steps['no relationships'] = $server->json('POST', '/api/ciphers/import', ['folders' => [], 'ciphers' => []], $auth);
            $steps['negative relationship'] = $server->json('POST', '/api/ciphers/import', ['folders' => [], 'folderRelationships' => [['key' => -1, 'value' => 0]], 'ciphers' => []], $auth);
            $steps['folder without name'] = $server->json('POST', '/api/ciphers/import', ['folders' => [['id' => $existing]], 'folderRelationships' => [], 'ciphers' => []], $auth);
            $steps['nothing'] = $server->json('POST', '/api/ciphers/import', ['folders' => [], 'folderRelationships' => [], 'ciphers' => []], $auth);
            $list = $server->send('GET', '/api/ciphers', $auth);
            $steps['ciphers after all'] = $list->withBody(self::sortedByName($list->body));

            return $steps;
        });
    }

    /**
     * A secure note, the cipher with the least data.
     *
     * @param  array<string, string>  $auth
     */
    public static function note(Server $server, array $auth, string $userId, string $name): string
    {
        return $server->json('POST', '/api/ciphers', [...self::cipher($userId, 2, $name), 'secureNote' => ['type' => 0]], $auth)->jsonString('id');
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

        $list['data'] = self::byName($list['data']);

        return (string) json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<mixed>  $items
     * @return list<mixed>
     */
    public static function byName(array $items): array
    {
        usort($items, static fn (mixed $a, mixed $b): int => strcmp(
            is_array($a) && is_string($a['name'] ?? null) ? $a['name'] : '',
            is_array($b) && is_string($b['name'] ?? null) ? $b['name'] : '',
        ));

        return $items;
    }
}
