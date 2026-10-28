<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * The organization import and export.
 */
class ImportExportTest extends DifferentialTestCase
{
    #[Parity('route:POST /api/ciphers/import-organization')]
    #[Parity('route:GET /api/organizations/{org_id}/export')]
    public function test_import_and_export(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner, $ownerUserId] = CipherTest::session($server, 'import-owner@example.com');
            [$member, $memberUserId] = CipherTest::session($server, 'import-member@example.com');
            $org = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            $first = $server->send('GET', "/api/organizations/{$org}/collections", $owner)->jsonString('data.0.id');
            $memberId = OrganizationTest::addMember($server, $owner, $org, 'import-member@example.com');
            $server->json('PUT', "/api/organizations/{$org}/collections/{$first}", ['name' => '2.first', 'groups' => [],
                'users' => [['id' => $memberId, 'readOnly' => true, 'hidePasswords' => false, 'manage' => false]]], $owner);
            $import = "/api/ciphers/import-organization?organizationId={$org}";
            $steps = [];

            $steps['import'] = $server->json('POST', $import, [
                'ciphers' => [
                    OrganizationTest::note($ownerUserId, '2.one', ['folderId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']),
                    OrganizationTest::note($ownerUserId, '2.two', ['organizationId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']),
                    // Broken ones are skipped.
                    ['type' => 1, 'name' => '2.broken', 'encryptedFor' => $ownerUserId],
                ],
                'collections' => [['name' => '2.ignored', 'id' => $first], ['name' => '2.new', 'externalId' => 'ext', 'groups' => [], 'users' => []]],
                'collectionRelationships' => [['key' => 0, 'value' => 0], ['key' => 1, 'value' => 1], ['key' => 0, 'value' => 1], ['key' => 2, 'value' => 1]],
            ], $owner);
            $steps['organization details'] = self::sortedCollectionIds(OrganizationTest::byName($server->send('GET', "/api/ciphers/organization-details?organizationId={$org}", $owner)));
            $steps['collections'] = OrganizationTest::byName($server->send('GET', "/api/organizations/{$org}/collections", $owner));
            $steps['export'] = self::sortedExport($server->send('GET', "/api/organizations/{$org}/export", $owner));
            $steps['export as member'] = $server->send('GET', "/api/organizations/{$org}/export", $member);
            $steps['export of an invalid organization'] = $server->send('GET', '/api/organizations/nope/export', $owner);

            $steps['bad relation'] = $server->json('POST', $import, ['ciphers' => [OrganizationTest::note($ownerUserId, '2.three')], 'collections' => [],
                'collectionRelationships' => [['key' => 0, 'value' => 5]]], $owner);
            $steps['too long notes'] = $server->json('POST', $import, ['ciphers' => [OrganizationTest::note($ownerUserId, '2.x', ['notes' => str_repeat('a', 10001)])], 'collections' => [],
                'collectionRelationships' => []], $owner);
            $steps['bad body'] = $server->json('POST', $import, ['ciphers' => []], $owner);
            $steps['without organization'] = $server->json('POST', '/api/ciphers/import-organization', ['ciphers' => [], 'collections' => [], 'collectionRelationships' => []], $owner);
            $steps['member into a read-only collection'] = $server->json('POST', $import, ['ciphers' => [OrganizationTest::note($memberUserId, '2.m')],
                'collections' => [['name' => '2.first', 'id' => $first]], 'collectionRelationships' => [['key' => 0, 'value' => 0]]], $member);
            $steps['member into a new collection'] = $server->json('POST', $import, ['ciphers' => [], 'collections' => [['name' => '2.mine']],
                'collectionRelationships' => []], $member);
            $steps['member without collections'] = $server->json('POST', $import, ['ciphers' => [OrganizationTest::note($memberUserId, '2.loose')], 'collections' => [],
                'collectionRelationships' => []], $member);
            $steps['organization details at last'] = self::sortedCollectionIds(OrganizationTest::byName($server->send('GET', "/api/ciphers/organization-details?organizationId={$org}", $owner)));

            return $steps;
        });
    }

    /**
     * The collection ids of every cipher in sorted order.
     */
    private static function sortedCollectionIds(Exchange $exchange): Exchange
    {
        $body = json_decode($exchange->body, true);
        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            return $exchange;
        }
        foreach ($body['data'] as &$cipher) {
            if (is_array($cipher) && is_array($cipher['collectionIds'] ?? null)) {
                sort($cipher['collectionIds']);
            }
        }
        unset($cipher);

        return $exchange->withBody((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The export's collections and ciphers in name order.
     */
    private static function sortedExport(Exchange $exchange): Exchange
    {
        $export = json_decode($exchange->body, true);
        if (! is_array($export)) {
            return $exchange;
        }
        foreach (['collections', 'ciphers'] as $key) {
            if (is_array($export[$key] ?? null)) {
                $export[$key] = CipherTest::byName($export[$key]);
            }
        }
        if (is_array($export['ciphers'] ?? null)) {
            $export['ciphers'] = array_map(static function (mixed $cipher): mixed {
                if (is_array($cipher) && is_array($cipher['collectionIds'] ?? null)) {
                    sort($cipher['collectionIds']);
                }

                return $cipher;
            }, $export['ciphers']);
        }

        return $exchange->withBody((string) json_encode($export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
