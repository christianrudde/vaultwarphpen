<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * Groups: what clients see without them (the default), and with
 * ORG_GROUPS_ENABLED on the third pair of servers (VW_FEATURES_*), including the access
 * members get through their groups.
 */
class GroupTest extends DifferentialTestCase
{
    private const string UNKNOWN = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public function test_groups_disabled(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'nogroups-owner@example.com');
            $org = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            $group = ['name' => 'Group', 'accessAll' => false, 'collections' => [], 'users' => []];
            $unknown = self::UNKNOWN;
            $steps = [];

            $steps['list'] = $server->send('GET', "/api/organizations/{$org}/groups", $owner);
            $steps['details'] = $server->send('GET', "/api/organizations/{$org}/groups/details", $owner);
            $steps['create'] = $server->json('POST', "/api/organizations/{$org}/groups", $group, $owner);
            $steps['create malformed'] = $server->json('POST', "/api/organizations/{$org}/groups", ['name' => 'Group'], $owner);
            $steps['get'] = $server->send('GET', "/api/organizations/{$org}/groups/{$unknown}", $owner);
            $steps['get details'] = $server->send('GET', "/api/organizations/{$org}/groups/{$unknown}/details", $owner);
            $steps['get members'] = $server->send('GET', "/api/organizations/{$org}/groups/{$unknown}/users", $owner);
            $steps['update'] = $server->json('PUT', "/api/organizations/{$org}/groups/{$unknown}", $group, $owner);
            $steps['delete'] = $server->send('DELETE', "/api/organizations/{$org}/groups/{$unknown}", $owner);
            $steps['bulk delete'] = $server->json('DELETE', "/api/organizations/{$org}/groups", ['ids' => []], $owner);
            $steps['member with groups'] = $server->send('GET', "/api/organizations/{$org}/users?includeGroups=true", $owner);

            return $steps;
        });
    }

    #[Parity('config:ORG_GROUPS_ENABLED')]
    #[Parity('route:GET /api/organizations/{org_id}/groups')]
    #[Parity('route:GET /api/organizations/{org_id}/groups/details')]
    #[Parity('route:POST /api/organizations/{org_id}/groups')]
    #[Parity('route:GET /api/organizations/{org_id}/groups/{group_id}')]
    #[Parity('route:PUT /api/organizations/{org_id}/groups/{group_id}')]
    #[Parity('route:GET /api/organizations/{org_id}/groups/{group_id}/details')]
    #[Parity('route:GET /api/organizations/{org_id}/groups/{group_id}/users')]
    #[Parity('route:DELETE /api/organizations/{org_id}/groups/{group_id}')]
    #[Parity('route:DELETE /api/organizations/{org_id}/groups')]
    public function test_groups(): void
    {
        $this->assertSameFlowsOn((string) getenv('VW_FEATURES_REFERENCE_URL'), (string) getenv('VW_FEATURES_SUBJECT_URL'), function (Server $server): array {
            [$owner, $ownerUserId] = CipherTest::session($server, 'groups-owner@example.com');
            [$member, $memberUserId] = CipherTest::session($server, 'groups-member@example.com');
            [$manager] = CipherTest::session($server, 'groups-manager@example.com');
            $org = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            $first = $server->send('GET', "/api/organizations/{$org}/collections", $owner)->jsonString('data.0.id');
            $second = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.second', 'groups' => [], 'users' => []], $owner)->jsonString('id');
            $memberId = OrganizationTest::addMember($server, $owner, $org, 'groups-member@example.com');
            $managerId = OrganizationTest::addMember($server, $owner, $org, 'groups-manager@example.com');
            $server->json('PUT', "/api/organizations/{$org}/users/{$managerId}", ['type' => 3, 'collections' => []], $owner);
            $unknown = self::UNKNOWN;
            $groups = "/api/organizations/{$org}/groups";
            $steps = [];

            $steps['create'] = $created = $server->json('POST', $groups, ['name' => 'Readers', 'accessAll' => false, 'externalId' => 'ext-1',
                'collections' => [self::access($first, readOnly: true)], 'users' => [$memberId]], $owner);
            $group = $created->jsonString('id');
            $steps['create with access to all'] = $created = $server->json('POST', $groups, ['name' => 'Everything', 'accessAll' => true, 'collections' => [], 'users' => []], $owner);
            $all = $created->jsonString('id');
            $steps['create with a blank external id'] = $server->json('POST', $groups, ['name' => 'Blank', 'externalId' => '  ', 'collections' => [], 'users' => []], $owner);
            $steps['create for an unknown collection'] = $server->json('POST', $groups, ['name' => 'X', 'collections' => [self::access($unknown)], 'users' => []], $owner);
            $steps['create for an unknown member'] = $server->json('POST', $groups, ['name' => 'X', 'collections' => [], 'users' => [$unknown]], $owner);
            $steps['create with null access to all'] = $server->json('POST', $groups, ['name' => 'X', 'accessAll' => null, 'collections' => [], 'users' => []], $owner);
            $steps['create without users'] = $server->json('POST', $groups, ['name' => 'X', 'collections' => []], $owner);
            $steps['create as manager'] = $server->json('POST', $groups, ['name' => 'X', 'collections' => [], 'users' => []], $manager);

            $steps['list'] = $server->send('GET', $groups, $owner);
            $steps['details'] = $server->send('GET', "{$groups}/details", $owner);
            $steps['one'] = $server->send('GET', "{$groups}/{$group}", $owner);
            $steps['one in detail'] = $server->send('GET', "{$groups}/{$group}/details", $owner);
            $steps['its members'] = $server->send('GET', "{$groups}/{$group}/users", $owner);
            $steps['unknown'] = $server->send('GET', "{$groups}/{$unknown}", $owner);
            $steps['members of an unknown group'] = $server->send('GET', "{$groups}/{$unknown}/users", $owner);
            $steps['invalid id'] = $server->send('GET', "{$groups}/nope", $owner);
            $steps['list as member'] = $server->send('GET', $groups, $member);
            $steps['list as manager without collections'] = $server->send('GET', $groups, $manager);

            $cipher = $server->json('POST', '/api/ciphers/create', ['cipher' => OrganizationTest::note($ownerUserId, '2.shared', ['organizationId' => $org]),
                'collectionIds' => [$first]], $owner)->jsonString('id');
            $steps['member reads through the group'] = $server->send('GET', "/api/ciphers/{$cipher}", $member);
            $steps['member sync'] = OrganizationTest::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $member));
            $steps['member collections'] = $server->send('GET', '/api/collections', $member);
            $steps['member updates read-only'] = $server->json('PUT', "/api/ciphers/{$cipher}", OrganizationTest::note($memberUserId, '2.x', ['organizationId' => $org]), $member);

            $steps['update'] = $server->json('PUT', "{$groups}/{$group}", ['name' => 'Writers', 'accessAll' => false, 'externalId' => 'changed',
                'collections' => [self::access($first, manage: true)], 'users' => [$memberId, $managerId]], $owner);
            $steps['update unknown'] = $server->json('PUT', "{$groups}/{$unknown}", ['name' => 'X', 'collections' => [], 'users' => []], $owner);
            $steps['update for an unknown member'] = $server->json('PUT', "{$groups}/{$group}", ['name' => 'X', 'collections' => [], 'users' => [$unknown]], $owner);
            $steps['member reads again'] = $server->send('GET', "/api/ciphers/{$cipher}", $member);
            $steps['member updates'] = $server->json('PUT', "/api/ciphers/{$cipher}", OrganizationTest::note($memberUserId, '2.by-member', ['organizationId' => $org]), $member);
            $steps['manager lists groups'] = $server->send('GET', $groups, $manager);
            $steps['manager lists group details'] = $server->send('GET', "{$groups}/details", $manager);
            $steps['manager collection details'] = OrganizationTest::byName($server->send('GET', "/api/organizations/{$org}/collections/details", $manager));
            $steps['manager collection detail'] = $server->send('GET', "/api/organizations/{$org}/collections/{$first}/details", $manager);
            $steps['manager sync'] = OrganizationTest::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $manager));

            $steps['members with groups'] = $server->send('GET', "/api/organizations/{$org}/users?includeCollections=true&includeGroups=true", $owner);
            $steps['member with groups'] = $server->send('GET', "/api/organizations/{$org}/users/{$memberId}?includeGroups=true", $owner);
            $steps['collection details'] = OrganizationTest::byName($server->send('GET', "/api/organizations/{$org}/collections/details", $owner));
            $steps['collection detail'] = $server->send('GET', "/api/organizations/{$org}/collections/{$first}/details", $owner);

            $steps['member joins the group with access to all'] = $server->json('PUT', "/api/organizations/{$org}/users/{$memberId}", ['type' => 2, 'collections' => [], 'groups' => [$all]], $owner);
            $steps['member with access to all'] = $server->send('GET', "/api/organizations/{$org}/users/{$memberId}?includeCollections=true&includeGroups=true", $owner);
            $hidden = $server->json('POST', '/api/ciphers/create', ['cipher' => OrganizationTest::note($ownerUserId, '2.second', ['organizationId' => $org]),
                'collectionIds' => [$second]], $owner)->jsonString('id');
            $steps['member reads any collection'] = $server->send('GET', "/api/ciphers/{$hidden}", $member);
            $steps['member sync with access to all'] = OrganizationTest::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $member));
            $steps['member collections with access to all'] = OrganizationTest::byName($server->send('GET', '/api/collections', $member));
            $steps['member joins an unknown group'] = $server->json('PUT', "/api/organizations/{$org}/users/{$memberId}", ['type' => 2, 'collections' => [], 'groups' => [$unknown]], $owner);

            $steps['invite into a group'] = $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => ['groups-new@example.com'], 'groups' => [$group], 'type' => 2], $owner);
            $steps['group members after the invitation'] = $server->send('GET', "{$groups}/{$group}/users", $owner);
            $steps['collection for a group'] = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.third',
                'groups' => [self::access($group, readOnly: true, hidePasswords: true)], 'users' => []], $owner);
            $steps['collection for an unknown group'] = $server->json('POST', "/api/organizations/{$org}/collections", ['name' => '2.x', 'groups' => [self::access($unknown)], 'users' => []], $owner);
            $steps['bulk access for a group'] = $server->json('POST', "/api/organizations/{$org}/collections/bulk-access", ['collectionIds' => [$second],
                'groups' => [self::access($all)], 'users' => []], $owner);
            $steps['collection update for a group'] = $server->json('PUT', "/api/organizations/{$org}/collections/{$second}", ['name' => '2.second',
                'groups' => [self::access($group, hidePasswords: true)], 'users' => []], $owner);
            $steps['group after the collection changes'] = self::sortedCollections($server->send('GET', "{$groups}/{$group}/details", $owner));
            $steps['member reads with hidden passwords'] = $server->send('GET', "/api/ciphers/{$hidden}", $manager);

            $steps['delete'] = $server->send('DELETE', "{$groups}/{$all}", $owner);
            $steps['delete again'] = $server->send('DELETE', "{$groups}/{$all}", $owner);
            $steps['delete as manager'] = $server->send('DELETE', "{$groups}/{$group}", $manager);
            $steps['bulk delete'] = $server->json('DELETE', $groups, ['ids' => [$group, $unknown]], $owner);
            $steps['list at last'] = $server->send('GET', $groups, $owner);
            $steps['member sync at last'] = OrganizationTest::sortedSync($server->send('GET', '/api/sync?excludeDomains=true', $member));

            return $steps;
        });
    }

    /**
     * A collection or group access, as clients send it.
     *
     * @return array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}
     */
    private static function access(string $id, bool $readOnly = false, bool $hidePasswords = false, bool $manage = false): array
    {
        return ['id' => $id, 'readOnly' => $readOnly, 'hidePasswords' => $hidePasswords, 'manage' => $manage];
    }

    /**
     * The group's collections by their flags; the servers list them in their own order.
     */
    private static function sortedCollections(Exchange $exchange): Exchange
    {
        $group = json_decode($exchange->body, true);
        if (! is_array($group) || ! is_array($group['collections'] ?? null)) {
            return $exchange;
        }
        usort($group['collections'], static fn (mixed $a, mixed $b): int => [is_array($a) ? $a['readOnly'] ?? null : null, is_array($a) ? $a['hidePasswords'] ?? null : null]
            <=> [is_array($b) ? $b['readOnly'] ?? null : null, is_array($b) ? $b['hidePasswords'] ?? null : null]);

        return $exchange->withBody((string) json_encode($group, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
