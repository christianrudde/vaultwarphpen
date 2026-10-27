<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * GET /api/sync and the equivalent domains on both servers.
 */
class SyncTest extends DifferentialTestCase
{
    #[Parity('route:GET /api/sync')]
    public function test_sync(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = CipherTest::session($server, 'sync@example.com');
            $steps = [];

            $steps['empty'] = $server->send('GET', '/api/sync', $auth);

            $folder = $server->json('POST', '/api/folders', ['name' => '2.folder'], $auth)->jsonString('id');
            $server->json('POST', '/api/folders', ['name' => '2.other'], $auth);
            $server->json('POST', '/api/ciphers', [...CipherTest::cipher($userId, 1, '2.login'), 'folderId' => $folder, 'favorite' => true,
                'login' => ['username' => '2.u', 'uris' => [['uri' => '2.uri', 'match' => null]]]], $auth);
            $server->json('POST', '/api/ciphers', [...CipherTest::cipher($userId, 5, '2.ssh'),
                'sshKey' => ['privateKey' => '2.p', 'publicKey' => '2.q', 'keyFingerprint' => '2.f']], $auth);
            $trashed = CipherTest::note($server, $auth, $userId, '2.trashed');
            $server->send('PUT', "/api/ciphers/{$trashed}/delete", $auth);
            $archived = CipherTest::note($server, $auth, $userId, '2.archived');
            $server->send('PUT', "/api/ciphers/{$archived}/archive", $auth);

            $steps['vault'] = self::sorted($server->send('GET', '/api/sync', $auth));
            foreach (['2024.11.30', '2024.12.0', '2025.1.0-beta', '2025.10.1+build', '2025.01.0', 'nonsense'] as $version) {
                $steps["client {$version}"] = self::sorted($server->send('GET', '/api/sync', [...$auth, 'Bitwarden-Client-Version' => $version]));
            }
            foreach (['excludeDomains=true', 'excludeDomains', 'excludeDomains=YES', 'excludeDomains=off', 'excludeDomains=1',
                'excludeDomains=true&excludeDomains=true', 'excludedomains=true', 'exclude%44omains=true', 'other=1'] as $query) {
                $steps["query {$query}"] = self::sorted($server->send('GET', "/api/sync?{$query}", $auth));
            }
            $steps['unauthenticated'] = $server->send('GET', '/api/sync?excludeDomains=1', ['Accept' => 'application/json']);

            return $steps;
        });
    }

    #[Parity('route:GET /api/settings/domains')]
    #[Parity('route:POST /api/settings/domains')]
    #[Parity('route:PUT /api/settings/domains')]
    public function test_equivalent_domains(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth] = CipherTest::session($server, 'domains@example.com');
            $steps = [];

            $steps['default'] = $server->send('GET', '/api/settings/domains', $auth);
            $steps['post'] = $server->json('POST', '/api/settings/domains', ['excludedGlobalEquivalentDomains' => [2, 3, 999], 'equivalentDomains' => [['a.example', 'b.example'], []]], $auth);
            $steps['after post'] = $server->send('GET', '/api/settings/domains', $auth);
            $steps['in the sync'] = $server->send('GET', '/api/sync', $auth);
            $steps['put only groups'] = $server->json('PUT', '/api/settings/domains', ['equivalentDomains' => [['c.example']]], $auth);
            $steps['after put'] = $server->send('GET', '/api/settings/domains', $auth);
            $steps['nulls'] = $server->json('PUT', '/api/settings/domains', ['excludedGlobalEquivalentDomains' => null, 'equivalentDomains' => null], $auth);
            $steps['numbers as domains'] = $server->json('POST', '/api/settings/domains', ['equivalentDomains' => [[1]]], $auth);
            $steps['strings as types'] = $server->json('POST', '/api/settings/domains', ['excludedGlobalEquivalentDomains' => ['2']], $auth);
            $steps['a list'] = $server->json('POST', '/api/settings/domains', [], $auth);
            $steps['unauthenticated'] = $server->send('GET', '/api/settings/domains', ['Accept' => 'application/json']);

            return $steps;
        });
    }

    /**
     * Ciphers and folders in name order; the servers store them in their own order.
     */
    private static function sorted(Exchange $exchange): Exchange
    {
        $sync = json_decode($exchange->body, true);
        if (! is_array($sync)) {
            return $exchange;
        }

        foreach (['ciphers', 'folders'] as $key) {
            if (is_array($sync[$key] ?? null)) {
                $sync[$key] = CipherTest::byName($sync[$key]);
            }
        }

        return $exchange->withBody((string) json_encode($sync, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
