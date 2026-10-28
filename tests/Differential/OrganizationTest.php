<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * Organizations, members, collections and organization ciphers on both servers.
 */
class OrganizationTest extends DifferentialTestCase
{
    #[Parity('route:POST /api/organizations')]
    #[Parity('route:GET /api/organizations/{org_id}')]
    #[Parity('route:PUT /api/organizations/{org_id}')]
    #[Parity('route:DELETE /api/organizations/{org_id}')]
    #[Parity('route:POST /api/organizations/{org_id}/keys')]
    #[Parity('route:GET /api/organizations/{org_id}/keys')]
    #[Parity('route:GET /api/organizations/{org_id}/public-key')]
    public function test_organizations(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'org-owner@example.com');
            [$other] = CipherTest::session($server, 'org-other@example.com');
            $steps = [];

            $steps['create'] = $created = $server->json('POST', '/api/organizations', self::org(), $owner);
            $org = $created->jsonString('id');
            $steps['create with keys'] = $server->json('POST', '/api/organizations', [...self::org(), 'name' => '2.keyed',
                'keys' => ['encryptedPrivateKey' => '2.private', 'publicKey' => 'public']], $owner);
            $steps['create without plan'] = $server->json('POST', '/api/organizations', array_diff_key(self::org(), ['planType' => true]), $owner);
            $steps['create with a bad email'] = $server->json('POST', '/api/organizations', [...self::org(), 'billingEmail' => 'nope'], $owner);
            $steps['profile'] = self::sortedProfile($server->send('GET', '/api/accounts/profile', $owner));
            $steps['get'] = $server->send('GET', "/api/organizations/{$org}", $owner);
            $steps['get as another user'] = $server->send('GET', "/api/organizations/{$org}", $other);
            $steps['get unknown'] = $server->send('GET', '/api/organizations/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $owner);
            $steps['get invalid id'] = $server->send('GET', '/api/organizations/nope', $owner);
            $steps['update'] = $server->json('PUT', "/api/organizations/{$org}", ['billingEmail' => 'NEW@example.com', 'name' => '2.renamed'], $owner);
            $steps['update with a bad email'] = $server->json('PUT', "/api/organizations/{$org}", ['billingEmail' => 'nope', 'name' => '2.x'], $owner);
            $steps['update with an escaped email'] = $server->json('PUT', "/api/organizations/{$org}", ['billingEmail' => "o'neil\x01", 'name' => '2.x'], $owner);
            $steps['keys'] = $server->json('POST', "/api/organizations/{$org}/keys", ['encryptedPrivateKey' => '2.private', 'publicKey' => 'public'], $owner);
            $steps['keys again'] = $server->json('POST', "/api/organizations/{$org}/keys", ['encryptedPrivateKey' => '2.x', 'publicKey' => 'x'], $owner);
            $steps['public key'] = $server->send('GET', "/api/organizations/{$org}/public-key", $owner);
            $steps['old keys route'] = $server->send('GET', "/api/organizations/{$org}/keys", $owner);
            $steps['delete with a wrong password'] = $server->json('DELETE', "/api/organizations/{$org}", ['masterPasswordHash' => 'wrong'], $owner);
            $steps['delete as another user'] = $server->json('DELETE', "/api/organizations/{$org}", ['masterPasswordHash' => 'client-hash'], $other);
            $steps['delete'] = $server->json('DELETE', "/api/organizations/{$org}", ['masterPasswordHash' => 'client-hash'], $owner);
            $steps['get deleted'] = $server->send('GET', "/api/organizations/{$org}", $owner);

            return $steps;
        });
    }

    #[Parity('route:GET /api/organizations/{org_id}/users')]
    #[Parity('route:GET /api/organizations/{org_id}/users/mini-details')]
    #[Parity('route:GET /api/organizations/{org_id}/users/{member_id}')]
    #[Parity('route:POST /api/organizations/{org_id}/users/invite')]
    #[Parity('route:POST /api/organizations/{org_id}/users/reinvite')]
    #[Parity('route:POST /api/organizations/{org_id}/users/{member_id}/reinvite')]
    #[Parity('route:POST /api/organizations/{org_id}/users/confirm')]
    #[Parity('route:POST /api/organizations/{org_id}/users/{member_id}/confirm')]
    #[Parity('route:PUT /api/organizations/{org_id}/users/{member_id}')]
    #[Parity('route:DELETE /api/organizations/{org_id}/users/{member_id}')]
    #[Parity('route:DELETE /api/organizations/{org_id}/users')]
    #[Parity('route:POST /api/organizations/{org_id}/users/public-keys')]
    #[Parity('route:PUT /api/organizations/{org_id}/users/{member_id}/revoke')]
    #[Parity('route:PUT /api/organizations/{org_id}/users/revoke')]
    #[Parity('route:PUT /api/organizations/{org_id}/users/{member_id}/restore')]
    #[Parity('route:PUT /api/organizations/{org_id}/users/{member_id}/restore/vnext')]
    #[Parity('route:PUT /api/organizations/{org_id}/users/restore')]
    #[Parity('route:POST /api/organizations/{org_id}/leave')]
    public function test_members(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'members-owner@example.com');
            [$member] = CipherTest::session($server, 'members-member@example.com');
            $org = $server->json('POST', '/api/organizations', self::org(), $owner)->jsonString('id');
            $collection = $server->send('GET', "/api/organizations/{$org}/collections", $owner)->jsonString('data.0.id');
            $steps = [];

            $steps['invite'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ['members-member@example.com', 'members-new@example.com'],
                'groups' => [], 'type' => 2, 'collections' => [['id' => $collection, 'readOnly' => true, 'hidePasswords' => false, 'manage' => false]]], $owner);
            $steps['invite again'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ['members-member@example.com'], 'groups' => [], 'type' => 2], $owner);
            $steps['invite with a bad type'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ['x@example.com'], 'groups' => [], 'type' => 7], $owner);
            $steps['invite to an unknown collection'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ['x@example.com'], 'groups' => [], 'type' => 2,
                'collections' => [['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'readOnly' => false, 'hidePasswords' => false, 'manage' => false]]], $owner);
            $steps['invite to an unknown group'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ['x@example.com'], 'groups' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'], 'type' => 2], $owner);
            $steps['invite without groups'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ['x@example.com'], 'type' => 2], $owner);
            $steps['invite with a quote'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ["members-o'neil@example.com"], 'groups' => [], 'type' => 2], $owner);
            $steps['invite with a quote again'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ["members-o'neil@example.com"], 'groups' => [], 'type' => 2], $owner);
            $steps['invite an invalid email'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ["members-o'neil\x01@example.com"], 'groups' => [], 'type' => 2], $owner);

            $steps['members'] = $members = $server->send('GET', "/api/organizations/{$org}/users", $owner);
            $steps['members with collections'] = $server->send('GET', "/api/organizations/{$org}/users?includeCollections=true&includeGroups=true", $owner);
            $ids = self::memberIds($members);
            $memberId = $ids['members-member@example.com'];
            $newId = $ids['members-new@example.com'];
            $ownerId = $ids['members-owner@example.com'];
            $steps['mini details'] = $server->send('GET', "/api/organizations/{$org}/users/mini-details", $owner);
            $steps['one member'] = $server->send('GET', "/api/organizations/{$org}/users/{$memberId}", $owner);
            $steps['one member with groups'] = $server->send('GET', "/api/organizations/{$org}/users/{$memberId}?includeGroups=true", $owner);
            $steps['unknown member'] = $server->send('GET', "/api/organizations/{$org}/users/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee", $owner);
            $steps['accepted profile'] = $server->send('GET', '/api/accounts/profile', $member);
            $steps['members as accepted member'] = $server->send('GET', "/api/organizations/{$org}/users", $member);

            $steps['reinvite accepted'] = $server->send('POST', "/api/organizations/{$org}/users/{$memberId}/reinvite", $owner);
            $steps['bulk reinvite'] = $server->json('POST', "/api/organizations/{$org}/users/reinvite", ['ids' => [$newId, $memberId]], $owner);
            $steps['confirm invited'] = $server->json('POST', "/api/organizations/{$org}/users/{$newId}/confirm", ['key' => '4.k'], $owner);
            $steps['confirm without key'] = $server->send('POST', "/api/organizations/{$org}/users/{$memberId}/confirm", [...$owner, 'Content-Type' => 'application/json'], '{}');
            $steps['bulk confirm'] = $server->json('POST', "/api/organizations/{$org}/users/confirm", ['keys' => [['id' => $memberId, 'key' => '4.k'], ['id' => $newId, 'key' => '4.k']]], $owner);
            $steps['bulk confirm without keys'] = $server->send('POST', "/api/organizations/{$org}/users/confirm", [...$owner, 'Content-Type' => 'application/json'], '{}');
            $steps['confirmed profile'] = $server->send('GET', '/api/accounts/profile', $member);
            $steps['confirmed sync'] = $server->send('GET', '/api/sync?excludeDomains=true', $member);
            $steps['public keys'] = $server->json('POST', "/api/organizations/{$org}/users/public-keys", ['ids' => [$memberId, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']], $owner);

            $steps['edit to custom'] = $server->json('PUT', "/api/organizations/{$org}/users/{$memberId}", ['type' => 4, 'collections' => [],
                'permissions' => ['editAnyCollection' => true, 'deleteAnyCollection' => true, 'createNewCollections' => true]], $owner);
            $steps['custom member'] = $server->send('GET', "/api/organizations/{$org}/users/{$memberId}", $owner);
            $steps['custom profile'] = $server->send('GET', '/api/accounts/profile', $member);
            $steps['rename member'] = $server->json('PUT', '/api/accounts/profile', ['name' => 'Org Member', 'culture' => 'de-DE'], $member);
            $steps['rename member by post'] = $server->json('POST', '/api/accounts/profile', ['name' => 'Org Poster'], $member);
            $steps['member avatar'] = $server->json('PUT', '/api/accounts/avatar', ['avatarColor' => '#a1b2c3'], $member);
            $steps['edit to user with collection'] = $server->json('PUT', "/api/organizations/{$org}/users/{$memberId}", ['type' => '2',
                'collections' => [['id' => $collection, 'readOnly' => false, 'hidePasswords' => true, 'manage' => false]]], $owner);
            $steps['user member'] = $server->send('GET', "/api/organizations/{$org}/users/{$memberId}?includeCollections=true", $owner);
            $steps['edit with a bad type'] = $server->json('PUT', "/api/organizations/{$org}/users/{$memberId}", ['type' => 'Boss'], $owner);
            $steps['edit the last owner'] = $server->json('PUT', "/api/organizations/{$org}/users/{$ownerId}", ['type' => 2], $owner);

            $steps['revoke'] = $server->send('PUT', "/api/organizations/{$org}/users/{$memberId}/revoke", $owner);
            $steps['revoked member'] = $server->send('GET', "/api/organizations/{$org}/users/{$memberId}", $owner);
            $steps['revoked profile'] = $server->send('GET', '/api/accounts/profile', $member);
            $steps['revoked access'] = $server->send('GET', "/api/organizations/{$org}/public-key", $member);
            $steps['revoke again'] = $server->send('PUT', "/api/organizations/{$org}/users/{$memberId}/revoke", $owner);
            $steps['revoke yourself'] = $server->send('PUT', "/api/organizations/{$org}/users/{$ownerId}/revoke", $owner);
            $steps['bulk restore'] = $server->json('PUT', "/api/organizations/{$org}/users/restore", ['ids' => [$memberId, $newId]], $owner);
            $steps['restore active'] = $server->send('PUT', "/api/organizations/{$org}/users/{$memberId}/restore", $owner);
            $steps['bulk revoke'] = $server->json('PUT', "/api/organizations/{$org}/users/revoke", ['ids' => [$memberId, $ownerId]], $owner);
            $steps['bulk revoke without ids'] = $server->send('PUT', "/api/organizations/{$org}/users/revoke", [...$owner, 'Content-Type' => 'application/json'], '{}');
            $steps['restore vnext'] = $server->json('PUT', "/api/organizations/{$org}/users/{$memberId}/restore/vnext", ['defaultUserCollectionName' => '2.x'], $owner);

            $steps['delete the last owner'] = $server->send('DELETE', "/api/organizations/{$org}/users/{$ownerId}", $owner);
            $steps['bulk delete'] = $server->json('DELETE', "/api/organizations/{$org}/users", ['ids' => [$newId, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']], $owner);
            $steps['last owner leaves'] = $server->send('POST', "/api/organizations/{$org}/leave", $owner);
            $steps['member leaves'] = $server->send('POST', "/api/organizations/{$org}/leave", $member);
            $steps['members at last'] = $server->send('GET', "/api/organizations/{$org}/users", $owner);

            return $steps;
        });
    }

    #[Parity('route:GET /api/collections')]
    #[Parity('route:GET /api/organizations/{org_id}/collections')]
    #[Parity('route:GET /api/organizations/{org_id}/collections/details')]
    #[Parity('route:GET /api/organizations/{org_id}/collections/{col_id}/details')]
    #[Parity('route:GET /api/organizations/{org_id}/collections/{col_id}/users')]
    #[Parity('route:POST /api/organizations/{org_id}/collections')]
    #[Parity('route:POST /api/organizations/{org_id}/collections/bulk-access')]
    #[Parity('route:PUT /api/organizations/{org_id}/collections/{col_id}')]
    #[Parity('route:DELETE /api/organizations/{org_id}/collections/{col_id}')]
    #[Parity('route:DELETE /api/organizations/{org_id}/collections')]
    public function test_collections(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'collections-owner@example.com');
            [$member] = CipherTest::session($server, 'collections-member@example.com');
            $org = $server->json('POST', '/api/organizations', self::org(), $owner)->jsonString('id');
            $memberId = self::addMember($server, $owner, $org, 'collections-member@example.com');
            $first = $server->send('GET', "/api/organizations/{$org}/collections", $owner)->jsonString('data.0.id');
            $steps = [];

            $steps['create'] = $created = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.second', 'groups' => [], 'externalId' => 'ext',
                'users' => [['id' => $memberId, 'readOnly' => true, 'hidePasswords' => true, 'manage' => false]]], $owner);
            $second = $created->jsonString('id');
            $steps['create for an unknown member'] = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.x', 'groups' => [],
                'users' => [['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'readOnly' => true, 'hidePasswords' => true, 'manage' => false]]], $owner);
            $steps['create without users'] = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.x', 'groups' => []], $owner);
            $steps['create as member'] = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.x', 'groups' => [], 'users' => []], $member);
            $steps['list'] = self::byName($server->send('GET', "/api/organizations/{$org}/collections", $owner));
            $steps['details'] = self::byName($server->send('GET', "/api/organizations/{$org}/collections/details", $owner));
            $steps['detail'] = $server->send('GET', "/api/organizations/{$org}/collections/{$second}/details", $owner);
            $steps['detail of an unknown collection'] = $server->send('GET', "/api/organizations/{$org}/collections/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee/details", $owner);
            $steps['users'] = $server->send('GET', "/api/organizations/{$org}/collections/{$second}/users", $owner);
            $steps['users as member'] = $server->send('GET', "/api/organizations/{$org}/collections/{$second}/users", $member);
            $steps['member collections'] = $server->send('GET', '/api/collections', $member);
            $steps['owner collections'] = self::byName($server->send('GET', '/api/collections', $owner));
            $steps['member sync'] = $server->send('GET', '/api/sync?excludeDomains=true', $member);

            $steps['update'] = $server->json('PUT', "/api/organizations/{$org}/collections/{$second}", ['name' => '2.renamed', 'groups' => [], 'externalId' => ' ',
                'users' => [['id' => $memberId, 'readOnly' => false, 'hidePasswords' => false, 'manage' => true]]], $owner);
            $steps['member collections after'] = $server->send('GET', '/api/collections', $member);
            $steps['bulk access'] = $server->json('POST', "/api/organizations/{$org}/collections/bulk-access", ['collectionIds' => [$first], 'groups' => [],
                'users' => [['id' => $memberId, 'readOnly' => true, 'hidePasswords' => false, 'manage' => false]]], $owner);
            $steps['bulk access to an unknown collection'] = $server->json('POST', "/api/organizations/{$org}/collections/bulk-access", ['collectionIds' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
                'groups' => [], 'users' => []], $owner);
            $steps['member sync after'] = self::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $member));
            $steps['delete unknown'] = $server->send('DELETE', "/api/organizations/{$org}/collections/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee", $owner);
            $steps['delete'] = $server->send('DELETE', "/api/organizations/{$org}/collections/{$second}", $owner);
            $steps['bulk delete malformed'] = $server->json('DELETE', "/api/organizations/{$org}/collections", ['ids' => ['nope']], $owner);
            $steps['bulk delete'] = $server->json('DELETE', "/api/organizations/{$org}/collections", ['ids' => [$first]], $owner);
            $steps['list at last'] = $server->send('GET', "/api/organizations/{$org}/collections", $owner);

            return $steps;
        });
    }

    #[Parity('route:GET /api/ciphers/organization-details')]
    #[Parity('route:POST /api/ciphers/bulk-collections')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/share')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/share')]
    #[Parity('route:PUT /api/ciphers/share')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/collections')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/collections')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/collections_v2')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/collections_v2')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/collections-admin')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/collections-admin')]
    #[Parity('route:POST /api/ciphers/purge')]
    public function test_organization_ciphers(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner, $ownerId] = CipherTest::session($server, 'vault-owner@example.com');
            [$member, $memberUserId] = CipherTest::session($server, 'vault-member@example.com');
            $org = $server->json('POST', '/api/organizations', self::org(), $owner)->jsonString('id');
            $memberId = self::addMember($server, $owner, $org, 'vault-member@example.com');
            $first = $server->send('GET', "/api/organizations/{$org}/collections", $owner)->jsonString('data.0.id');
            $second = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.second', 'groups' => [],
                'users' => [['id' => $memberId, 'readOnly' => true, 'hidePasswords' => true, 'manage' => false]]], $owner)->jsonString('id');
            $steps = [];

            $steps['create'] = $created = $server->json('POST', '/api/ciphers/create', ['cipher' => self::note($ownerId, '2.shared', ['organizationId' => $org]), 'collectionIds' => [$second]], $owner);
            $cipher = $created->jsonString('id');
            $steps['create without collections'] = $server->json('POST', '/api/ciphers/create', ['cipher' => self::note($ownerId, '2.loose', ['organizationId' => $org]), 'collectionIds' => []], $owner);
            $steps['create into an unknown collection'] = $server->json('POST', '/api/ciphers/create', ['cipher' => self::note($ownerId, '2.x', ['organizationId' => $org]),
                'collectionIds' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']], $owner);
            $steps['member creates directly'] = $server->json('POST', '/api/ciphers', self::note($memberUserId, '2.x', ['organizationId' => $org]), $member);
            $steps['member reads'] = $server->send('GET', "/api/ciphers/{$cipher}", $member);
            $steps['member sync'] = self::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $member));
            $steps['member list'] = self::byName($server->send('GET', '/api/ciphers', $member));
            $steps['member updates'] = $server->json('PUT', "/api/ciphers/{$cipher}", self::note($memberUserId, '2.edit', ['organizationId' => $org]), $member);
            $steps['member deletes'] = $server->send('DELETE', "/api/ciphers/{$cipher}", $member);
            $steps['member moves collections'] = $server->json('PUT', "/api/ciphers/{$cipher}/collections", ['collectionIds' => [$first]], $member);
            $steps['owner reads'] = $server->send('GET', "/api/ciphers/{$cipher}", $owner);
            $steps['owner updates'] = $server->json('PUT', "/api/ciphers/{$cipher}", self::note($ownerId, '2.updated', ['organizationId' => $org]), $owner);
            $steps['owner updates without organization'] = $server->json('PUT', "/api/ciphers/{$cipher}", self::note($ownerId, '2.x'), $owner);

            $steps['collections v2'] = self::sortedIds($server->json('PUT', "/api/ciphers/{$cipher}/collections_v2", ['collectionIds' => [$first, $second]], $owner), 'cipher.collectionIds');
            $steps['collections'] = $server->json('POST', "/api/ciphers/{$cipher}/collections", ['CollectionIds' => [$second]], $owner);
            $steps['collections admin'] = $server->json('PUT', "/api/ciphers/{$cipher}/collections-admin", ['collectionIds' => [$first, $second]], $owner);
            $steps['collections of a personal cipher'] = $server->json('PUT', '/api/ciphers/'.CipherTest::note($server, $owner, $ownerId, '2.personal').'/collections', ['collectionIds' => []], $owner);
            $steps['organization details'] = self::byName($server->send('GET', "/api/ciphers/organization-details?organizationId={$org}", $owner));
            $steps['organization details as member'] = $server->send('GET', "/api/ciphers/organization-details?organizationId={$org}", $member);

            $steps['update collection access'] = $server->json('PUT', "/api/organizations/{$org}/collections/{$second}", ['name' => '2.second', 'groups' => [],
                'users' => [['id' => $memberId, 'readOnly' => false, 'hidePasswords' => false, 'manage' => false]]], $owner);
            $steps['member reads again'] = self::sortedIds($server->send('GET', "/api/ciphers/{$cipher}", $member), 'collectionIds');
            $steps['member updates now'] = self::sortedIds($server->json('PUT', "/api/ciphers/{$cipher}", self::note($memberUserId, '2.by-member', ['organizationId' => $org]), $member), 'collectionIds');

            $personal = CipherTest::note($server, $member, $memberUserId, '2.mine');
            $steps['share into a read-only collection'] = $server->json('PUT', "/api/ciphers/{$personal}/share", ['cipher' => self::note($memberUserId, '2.mine', ['organizationId' => $org]),
                'collectionIds' => [$first]], $member);
            $steps['share'] = $server->json('POST', "/api/ciphers/{$personal}/share", ['cipher' => self::note($memberUserId, '2.mine', ['organizationId' => $org]),
                'collectionIds' => [$second]], $member);
            $other = CipherTest::note($server, $member, $memberUserId, '2.other');
            $steps['bulk share'] = $server->json('PUT', '/api/ciphers/share', ['ciphers' => [self::note($memberUserId, '2.other', ['organizationId' => $org, 'id' => $other])],
                'collectionIds' => [$second]], $member);
            $steps['bulk share without ids'] = $server->json('PUT', '/api/ciphers/share', ['ciphers' => [self::note($memberUserId, '2.x', ['organizationId' => $org])], 'collectionIds' => [$second]], $member);
            $steps['bulk share nothing'] = $server->json('PUT', '/api/ciphers/share', ['ciphers' => [], 'collectionIds' => [$second]], $member);
            $steps['bulk collections'] = $server->json('POST', '/api/ciphers/bulk-collections', ['organizationId' => $org, 'cipherIds' => [$personal, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
                'collectionIds' => [$first], 'removeCollections' => false], $owner);
            $steps['after bulk collections'] = self::sortedIds($server->send('GET', "/api/ciphers/{$personal}", $owner), 'collectionIds');
            $steps['bulk collections as member'] = $server->json('POST', '/api/ciphers/bulk-collections', ['organizationId' => $org, 'cipherIds' => [$personal],
                'collectionIds' => [$first], 'removeCollections' => true], $member);
            $steps['bulk remove'] = $server->json('POST', '/api/ciphers/bulk-collections', ['organizationId' => $org, 'cipherIds' => [$personal],
                'collectionIds' => [$first], 'removeCollections' => true], $owner);
            $steps['owner sync'] = self::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $owner));

            $steps['purge as member'] = $server->json('POST', "/api/ciphers/purge?organizationId={$org}", ['masterPasswordHash' => 'client-hash'], $member);
            $steps['purge with a wrong password'] = $server->json('POST', "/api/ciphers/purge?organizationId={$org}", ['masterPasswordHash' => 'wrong'], $owner);
            $steps['purge'] = $server->json('POST', "/api/ciphers/purge?organizationId={$org}", ['masterPasswordHash' => 'client-hash'], $owner);
            $steps['member sync at last'] = self::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $member));

            return $steps;
        });
    }

    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/v2')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/{attachment_id}')]
    #[Parity('route:GET /api/ciphers/{cipher_id}/attachment/{attachment_id}')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment-admin')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/{attachment_id}/share')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/{attachment_id}/delete-admin')]
    #[Parity('route:DELETE /api/ciphers/{cipher_id}/attachment/{attachment_id}/admin')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/delete-admin')]
    #[Parity('route:PUT /api/ciphers/{cipher_id}/restore-admin')]
    #[Parity('route:PUT /api/ciphers/delete-admin')]
    #[Parity('route:PUT /api/ciphers/restore-admin')]
    #[Parity('route:DELETE /api/ciphers/admin')]
    #[Parity('route:DELETE /api/ciphers/{cipher_id}/admin')]
    public function test_organization_cipher_attachments_and_trash(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner, $ownerId] = CipherTest::session($server, 'trash-owner@example.com');
            [$member] = CipherTest::session($server, 'trash-member@example.com');
            $org = $server->json('POST', '/api/organizations', self::org(), $owner)->jsonString('id');
            $memberId = self::addMember($server, $owner, $org, 'trash-member@example.com');
            $collection = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.shared', 'groups' => [],
                'users' => [['id' => $memberId, 'readOnly' => true, 'hidePasswords' => false, 'manage' => false]]], $owner)->jsonString('id');
            $create = fn (string $name): string => $server->json('POST', '/api/ciphers/create', ['cipher' => self::note($ownerId, $name, ['organizationId' => $org]),
                'collectionIds' => [$collection]], $owner)->jsonString('id');
            $cipher = $create('2.files');
            $other = $create('2.other');
            $steps = [];

            $steps['attachment'] = $created = $server->json('POST', "/api/ciphers/{$cipher}/attachment/v2", ['key' => '2.key', 'fileName' => '2.name', 'fileSize' => 5], $owner);
            $attachment = $created->jsonString('attachmentId');
            $steps['upload'] = $server->multipart("/api/ciphers/{$cipher}/attachment/{$attachment}", [['data', 'hello', 'n']], $owner);
            $steps['member reads the attachment'] = $server->send('GET', "/api/ciphers/{$cipher}/attachment/{$attachment}", $member);
            $steps['read-only member adds one'] = $server->json('POST', "/api/ciphers/{$cipher}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => 1], $member);
            $steps['read-only member uploads'] = $server->multipart("/api/ciphers/{$cipher}/attachment", [['key', '2.k'], ['data', 'x', '2.n']], $member);
            $steps['read-only member deletes it'] = $server->send('DELETE', "/api/ciphers/{$cipher}/attachment/{$attachment}", $member);
            $steps['share'] = $shared = $server->multipart("/api/ciphers/{$cipher}/attachment/{$attachment}/share", [['key', '2.key2'], ['data', 'shared', '2.other']], $owner);
            $steps['delete admin'] = $server->send('POST', "/api/ciphers/{$cipher}/attachment/{$shared->jsonString('attachments.0.id')}/delete-admin", $owner);
            $steps['admin upload'] = $admin = $server->multipart("/api/ciphers/{$cipher}/attachment-admin", [['key', '2.key3'], ['data', 'third', '2.third']], $owner);
            $steps['delete admin by DELETE'] = $server->send('DELETE', "/api/ciphers/{$cipher}/attachment/{$admin->jsonString('attachments.0.id')}/admin", $owner);

            $steps['read-only member trashes'] = $server->send('PUT', "/api/ciphers/{$cipher}/delete", $member);
            $steps['trash admin'] = $server->send('PUT', "/api/ciphers/{$cipher}/delete-admin", $owner);
            $steps['member sees it in the trash'] = $server->send('GET', "/api/ciphers/{$cipher}", $member);
            $steps['read-only member restores'] = $server->send('PUT', "/api/ciphers/{$cipher}/restore", $member);
            $steps['restore admin'] = $server->send('PUT', "/api/ciphers/{$cipher}/restore-admin", $owner);
            $steps['bulk trash admin'] = $server->json('PUT', '/api/ciphers/delete-admin', ['ids' => [$cipher, $other], 'organizationId' => $org], $owner);
            $steps['bulk restore admin'] = self::byName($server->json('PUT', '/api/ciphers/restore-admin', ['ids' => [$cipher, $other], 'organizationId' => $org], $owner));

            $folder = $server->json('POST', '/api/folders', ['name' => '2.folder'], $member)->jsonString('id');
            $steps['member files it'] = $server->json('PUT', "/api/ciphers/{$cipher}/partial", ['folderId' => $folder, 'favorite' => true], $member);
            $steps['member moves it'] = $server->json('PUT', '/api/ciphers/move', ['folderId' => '', 'ids' => [$cipher]], $member);
            $steps['member archives it'] = $server->send('PUT', "/api/ciphers/{$cipher}/archive", $member);
            $steps['member unarchives it'] = $server->json('PUT', '/api/ciphers/unarchive', ['ids' => [$cipher]], $member);

            $steps['read-only member deletes'] = $server->send('DELETE', "/api/ciphers/{$cipher}", $member);
            $steps['bulk delete admin'] = $server->json('DELETE', '/api/ciphers/admin', ['ids' => [$other], 'organizationId' => $org], $owner);
            $steps['delete admin cipher'] = $server->send('DELETE', "/api/ciphers/{$cipher}/admin", $owner);
            $steps['member sync'] = self::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $member));

            return $steps;
        });
    }

    #[Parity('route:GET /api/plans')]
    #[Parity('route:GET /api/organizations/{_org_id}/billing/vnext/warnings')]
    #[Parity('route:GET /api/organizations/{_org_id}/billing/vnext/self-host/metadata')]
    public function test_plans_and_billing(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'billing-owner@example.com');
            [$stranger] = CipherTest::session($server, 'billing-stranger@example.com');
            $org = $server->json('POST', '/api/organizations', self::org(), $owner)->jsonString('id');
            $billing = "/api/organizations/{$org}/billing/vnext";

            return [
                'plans' => $server->send('GET', '/api/plans'),
                'warnings' => $server->send('GET', "{$billing}/warnings", $owner),
                'warnings for a stranger' => $server->send('GET', "{$billing}/warnings", $stranger),
                'warnings unauthenticated' => $server->send('GET', "{$billing}/warnings"),
                'metadata' => $server->send('GET', "{$billing}/self-host/metadata", $owner),
                'metadata of a bad id' => $server->send('GET', '/api/organizations/nope/billing/vnext/self-host/metadata', $owner),
            ];
        });
    }

    /**
     * A secure note.
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    public static function note(string $userId, string $name, array $more = []): array
    {
        return [...CipherTest::cipher($userId, 2, $name), 'secureNote' => ['type' => 0], ...$more];
    }

    /**
     * @return array<string, mixed>
     */
    public static function org(): array
    {
        return ['billingEmail' => 'billing@example.com', 'collectionName' => '2.first', 'key' => '4.owner-key', 'name' => '2.org', 'planType' => 0];
    }

    /**
     * Invites and confirms a user with a password; returns the membership id.
     *
     * @param  array<string, string>  $owner
     */
    public static function addMember(Server $server, array $owner, string $org, string $email): string
    {
        $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => [$email], 'groups' => [], 'type' => 2], $owner);
        $memberId = self::memberIds($server->send('GET', "/api/organizations/{$org}/users", $owner))[$email];
        $server->json('POST', "/api/organizations/{$org}/users/{$memberId}/confirm", ['key' => '4.member-key'], $owner);

        return $memberId;
    }

    /**
     * @return array<string, string> Membership id by email
     */
    public static function memberIds(Exchange $members): array
    {
        $ids = [];
        foreach ((array) $members->json('data') as $member) {
            if (is_array($member) && is_string($member['email'] ?? null) && is_string($member['id'] ?? null)) {
                $ids[$member['email']] = $member['id'];
            }
        }

        return $ids;
    }

    public static function byName(Exchange $exchange): Exchange
    {
        return $exchange->withBody(CipherTest::sortedByName($exchange->body));
    }

    /**
     * The ciphers, folders and collections of a sync in name order.
     */
    public static function sortedSync(Exchange $exchange): Exchange
    {
        $sync = json_decode($exchange->body, true);
        if (! is_array($sync)) {
            return $exchange;
        }
        foreach (['ciphers', 'folders', 'collections'] as $key) {
            if (is_array($sync[$key] ?? null)) {
                $sync[$key] = CipherTest::byName($sync[$key]);
            }
        }

        return $exchange->withBody((string) json_encode($sync, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * A profile with its organizations in name order; the servers read them by index.
     */
    public static function sortedProfile(Exchange $exchange): Exchange
    {
        $profile = json_decode($exchange->body, true);
        if (! is_array($profile)) {
            return $exchange;
        }
        foreach (['organizations', 'organizationsNew'] as $key) {
            if (is_array($profile[$key] ?? null)) {
                $profile[$key] = CipherTest::byName($profile[$key]);
            }
        }

        return $exchange->withBody((string) json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * A list of ids in sorted order; the servers read them in their own.
     */
    public static function sortedIds(Exchange $exchange, string $path): Exchange
    {
        $body = json_decode($exchange->body, true);
        if (! is_array($body)) {
            return $exchange;
        }
        $node = &$body;
        foreach (explode('.', $path) as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return $exchange;
            }
            $node = &$node[$key];
        }
        if (is_array($node)) {
            sort($node);
        }
        unset($node);

        return $exchange->withBody((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
