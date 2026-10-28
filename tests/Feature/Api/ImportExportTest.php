<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Cipher;
use App\Models\CollectionCipher;
use App\Models\Device;
use App\Models\OrgCollection;
use App\Models\User;
use App\Support\Random;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * The organization import and export (src/api/core/organizations.rs).
 */
class ImportExportTest extends TestCase
{
    use UsesVaultwardenDatabase;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000'];
    }

    public function test_import_and_export(): void
    {
        $this->useDatabase('sqlite');
        $owner = User::new('owner@example.com');
        $owner->setPassword('hash', 'user-key', false, null);
        $owner->save();
        $device = Device::new(Random::uuid(), $owner->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);
        $auth = ['Authorization' => 'Bearer '.LoginClaims::default($device, $owner, AuthMethod::Password, 'cli', $jwt)->token($jwt)];

        $org = $this->postJson('/api/organizations', ['billingEmail' => 'billing@example.com', 'collectionName' => '2.first',
            'key' => '4.owner-key', 'name' => '2.org', 'planType' => 0], $auth)->json('id');
        $this->assertIsString($org);
        $first = OrgCollection::findByOrganization($org)->first()?->uuid;
        $this->assertIsString($first);
        $note = static fn (string $name): array => ['type' => 2, 'name' => $name, 'encryptedFor' => $owner->uuid, 'secureNote' => ['type' => 0],
            'organizationId' => Random::uuid(), 'folderId' => Random::uuid()];

        $this->postJson("/api/ciphers/import-organization?organizationId={$org}", [
            'ciphers' => [$note('2.one'), $note('2.two'), ['type' => 1, 'name' => '2.broken', 'encryptedFor' => $owner->uuid]],
            'collections' => [['name' => '2.ignored', 'id' => $first], ['name' => '2.new']],
            'collectionRelationships' => [['key' => 0, 'value' => 0], ['key' => 1, 'value' => 1], ['key' => 0, 'value' => 1]],
        ], $auth)->assertStatus(200);

        // The broken cipher is skipped; the others belong to the organization.
        $this->assertSame(2, Cipher::findByOrg($org)->count());
        $this->assertSame(['2.first', '2.new'], OrgCollection::findByOrganization($org)->pluck('name')->sort()->values()->all());
        $one = Cipher::query()->where('name', '2.one')->first();
        $this->assertNotNull($one);
        $this->assertCount(2, CollectionCipher::collectionsOfCipher($one->uuid));

        $this->postJson("/api/ciphers/import-organization?organizationId={$org}", ['ciphers' => [$note('2.x')], 'collections' => [],
            'collectionRelationships' => [['key' => 0, 'value' => 3]]], $auth)
            ->assertStatus(400)->assertExactJson(['message' => 'Invalid collection relationship', 'validationErrors' => null, 'exceptionMessage' => null,
                'exceptionStackTrace' => null, 'innerExceptionMessage' => null, 'object' => 'error']);

        $this->get("/api/organizations/{$org}/export", $auth)->assertStatus(200)
            ->assertJsonCount(2, 'collections')->assertJsonCount(3, 'ciphers')
            ->assertJsonPath('ciphers.0.object', 'cipherDetails')->assertJsonPath('collections.0.object', 'collection');
    }
}
