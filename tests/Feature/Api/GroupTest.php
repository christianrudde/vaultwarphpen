<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Device;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Support\Random;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * Groups with ORG_GROUPS_ENABLED (src/api/core/organizations.rs,
 * src/db/models/group.rs) and the access members get through them.
 */
class GroupTest extends TestCase
{
    use UsesVaultwardenDatabase;

    /**
     * @var array<string, string>
     */
    private array $owner;

    /**
     * @var array<string, string>
     */
    private array $member;

    private User $memberUser;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000', 'ORG_GROUPS_ENABLED' => 'true'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');

        [, $this->owner] = $this->login('owner@example.com');
        [$this->memberUser, $this->member] = $this->login('member@example.com');
    }

    public function test_group_lifecycle(): void
    {
        $org = $this->createOrganization();
        $collection = $this->string($this->get("/api/organizations/{$org}/collections", $this->owner)->json('data.0.id'));
        $memberId = $this->addMember($org);

        $response = $this->postJson("/api/organizations/{$org}/groups", ['name' => 'Readers', 'externalId' => ' ',
            'collections' => [['id' => $collection, 'readOnly' => true, 'hidePasswords' => false, 'manage' => false]], 'users' => [$memberId]], $this->owner)
            ->assertStatus(200);
        $created = $this->string(Group::query()->value('uuid'));
        $response->assertExactJson(['externalId' => null, 'id' => $created, 'name' => 'Readers', 'object' => 'group', 'organizationId' => $org]);
        $this->assertFalse(Group::findByUuidAndOrg($created, $org)?->access_all);

        $this->postJson("/api/organizations/{$org}/groups", ['name' => 'X', 'accessAll' => null, 'collections' => [], 'users' => []], $this->owner)->assertStatus(422);
        $this->postJson("/api/organizations/{$org}/groups", ['name' => 'X', 'collections' => [['id' => Random::uuid(), 'readOnly' => false, 'hidePasswords' => false, 'manage' => false]], 'users' => []], $this->owner)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid collection');
        $this->postJson("/api/organizations/{$org}/groups", ['name' => 'X', 'collections' => [], 'users' => [Random::uuid()]], $this->owner)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid member');

        $this->get("/api/organizations/{$org}/groups", $this->owner)->assertJsonPath('data.0.name', 'Readers')->assertJsonPath('object', 'list');
        $this->get("/api/organizations/{$org}/groups/details", $this->owner)
            ->assertJsonPath('data.0.object', 'groupDetails')
            ->assertJsonPath('data.0.collections', [['hidePasswords' => false, 'id' => $collection, 'manage' => false, 'readOnly' => true]]);
        $this->get("/api/organizations/{$org}/groups/{$created}", $this->owner)->assertJsonPath('object', 'group');
        $this->get("/api/organizations/{$org}/groups/{$created}/users", $this->owner)->assertExactJson([$memberId]);
        $this->get("/api/organizations/{$org}/groups/".Random::uuid().'/users', $this->owner)->assertStatus(400)->assertJsonPath('message', 'Group could not be found!');
        $this->get("/api/organizations/{$org}/groups/".Random::uuid(), $this->owner)->assertStatus(400)->assertJsonPath('message', 'Group not found');

        // Members see their groups; the collections come along when groups are asked for.
        $this->get("/api/organizations/{$org}/users/{$memberId}?includeGroups=true", $this->owner)
            ->assertJsonPath('groups', [$created])->assertJsonPath('collections', []);
        $this->get("/api/organizations/{$org}/collections/{$collection}/details", $this->owner)
            ->assertJsonPath('groups', [['hidePasswords' => false, 'id' => $created, 'manage' => false, 'readOnly' => true]]);

        // The external id stays; members and collections are replaced.
        $this->putJson("/api/organizations/{$org}/groups/{$created}", ['name' => 'Writers', 'accessAll' => true, 'externalId' => 'new', 'collections' => [], 'users' => []], $this->owner)
            ->assertStatus(200)->assertJsonPath('name', 'Writers')->assertJsonPath('externalId', null);
        $this->get("/api/organizations/{$org}/groups/{$created}/users", $this->owner)->assertExactJson([]);
        $this->assertSame(0, DB::table('collections_groups')->count());

        $this->postJson("/api/organizations/{$org}/groups", ['name' => 'Second', 'collections' => [], 'users' => [$memberId]], $this->owner)->assertStatus(200);
        $this->json('DELETE', "/api/organizations/{$org}/groups", ['ids' => [$created, Random::uuid()]], $this->owner)
            ->assertStatus(400)->assertJsonPath('message', 'Group not found');
        $this->assertSame(['Second'], Group::query()->pluck('name')->all());

        // Leaving the organization ends the group memberships.
        $this->postJson("/api/organizations/{$org}/leave", [], $this->member)->assertStatus(200);
        $this->assertSame(0, DB::table('groups_users')->count());
        $this->json('DELETE', "/api/organizations/{$org}", ['masterPasswordHash' => 'hash'], $this->owner)->assertStatus(200);
        $this->assertSame(0, Group::query()->count());
    }

    public function test_access_through_groups(): void
    {
        $org = $this->createOrganization();
        $collection = $this->string($this->get("/api/organizations/{$org}/collections", $this->owner)->json('data.0.id'));
        $other = $this->string($this->postJson("/api/organizations/{$org}/collections", ['name' => '2.other', 'groups' => [], 'users' => []], $this->owner)->json('id'));
        $memberId = $this->addMember($org);
        $cipher = $this->orgCipher($org, $collection);
        $hidden = $this->orgCipher($org, $other);

        $this->get("/api/ciphers/{$cipher}", $this->member)->assertStatus(400);

        $group = $this->string($this->postJson("/api/organizations/{$org}/groups", ['name' => 'Readers',
            'collections' => [['id' => $collection, 'readOnly' => true, 'hidePasswords' => true, 'manage' => false]], 'users' => [$memberId]], $this->owner)->json('id'));
        $this->get("/api/ciphers/{$cipher}", $this->member)->assertStatus(200)
            ->assertJsonPath('edit', false)->assertJsonPath('viewPassword', false)->assertJsonPath('collectionIds', []);
        $this->get("/api/ciphers/{$hidden}", $this->member)->assertStatus(400);
        $this->get('/api/collections', $this->member)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $collection);
        $this->get('/api/sync?excludeDomains=true', $this->member)->assertJsonCount(1, 'ciphers')
            ->assertJsonPath('ciphers.0.edit', false)->assertJsonPath('collections.0.readOnly', true)->assertJsonPath('collections.0.hidePasswords', true);

        // Write access through a group shows the collection among the cipher's.
        $this->putJson("/api/organizations/{$org}/groups/{$group}", ['name' => 'Writers',
            'collections' => [['id' => $collection, 'readOnly' => false, 'hidePasswords' => false, 'manage' => false]], 'users' => [$memberId]], $this->owner)->assertStatus(200);
        $this->get("/api/ciphers/{$cipher}", $this->member)->assertJsonPath('edit', true)->assertJsonPath('collectionIds', [$collection]);

        // A direct access wins over the group's.
        DB::table('users_collections')->insert(['user_uuid' => $this->memberUser->uuid, 'collection_uuid' => $collection, 'read_only' => true, 'hide_passwords' => false, 'manage' => false]);
        $this->get("/api/ciphers/{$cipher}", $this->member)->assertJsonPath('edit', false);
        $this->get('/api/sync?excludeDomains=true', $this->member)->assertJsonPath('ciphers.0.edit', false);

        // A group with access to all reaches every collection.
        $this->postJson("/api/organizations/{$org}/groups", ['name' => 'All', 'accessAll' => true, 'collections' => [], 'users' => [$memberId]], $this->owner)->assertStatus(200);
        $this->get("/api/ciphers/{$hidden}", $this->member)->assertStatus(200)->assertJsonPath('edit', true);
        $this->get('/api/sync?excludeDomains=true', $this->member)->assertJsonCount(2, 'ciphers');
        $this->get("/api/organizations/{$org}/users/{$memberId}?includeCollections=true", $this->owner)->assertJsonPath('collections', []);

        // Revoked members keep their groups, but no access.
        $this->put("/api/organizations/{$org}/users/{$memberId}/revoke", [], $this->owner)->assertStatus(200);
        $this->get("/api/ciphers/{$hidden}", $this->member)->assertStatus(400);
        $this->assertSame(2, DB::table('groups_users')->count());
    }

    public function test_managers_list_groups_of_collections_they_manage(): void
    {
        $org = $this->createOrganization();
        $collection = $this->string($this->get("/api/organizations/{$org}/collections", $this->owner)->json('data.0.id'));
        $memberId = $this->addMember($org);
        $this->putJson("/api/organizations/{$org}/users/{$memberId}", ['type' => 3, 'collections' => []], $this->owner)->assertStatus(200);

        $this->get("/api/organizations/{$org}/groups", $this->member)->assertStatus(404)->assertJsonPath('message', 'Resource not found.');
        $this->postJson("/api/organizations/{$org}/groups", ['name' => 'Managers',
            'collections' => [['id' => $collection, 'readOnly' => false, 'hidePasswords' => false, 'manage' => true]], 'users' => [$memberId]], $this->owner)->assertStatus(200);
        $this->get("/api/organizations/{$org}/groups", $this->member)->assertStatus(200)->assertJsonPath('data.0.name', 'Managers');
        $this->get("/api/organizations/{$org}/groups/details", $this->member)->assertStatus(404);
        $this->get("/api/organizations/{$org}/collections/details", $this->member)->assertStatus(200)
            ->assertJsonPath('data.0.assigned', true)->assertJsonPath('data.0.manage', true);
    }

    private function orgCipher(string $orgId, string $collectionId): string
    {
        $owner = User::findByMail('owner@example.com');
        $this->assertNotNull($owner);

        return $this->string($this->postJson('/api/ciphers/create', ['cipher' => ['type' => 2, 'name' => '2.note', 'encryptedFor' => $owner->uuid,
            'secureNote' => ['type' => 0], 'organizationId' => $orgId],
            'collectionIds' => [$collectionId]], $this->owner)->assertStatus(200)->json('id'));
    }

    private function createOrganization(): string
    {
        return $this->string($this->postJson('/api/organizations', ['billingEmail' => 'billing@example.com', 'collectionName' => '2.first',
            'key' => '4.owner-key', 'name' => '2.org', 'planType' => 0], $this->owner)->assertStatus(200)->json('id'));
    }

    private function addMember(string $orgId): string
    {
        $this->postJson("/api/organizations/{$orgId}/users/invite", ['emails' => ['member@example.com'], 'groups' => [], 'type' => 2], $this->owner)->assertStatus(200);
        $memberId = Membership::query()->where('user_uuid', $this->memberUser->uuid)->value('uuid');
        $this->assertIsString($memberId);
        $this->postJson("/api/organizations/{$orgId}/users/{$memberId}/confirm", ['key' => '4.member-key'], $this->owner)->assertStatus(200);

        return $memberId;
    }

    /**
     * @return array{User, array<string, string>}
     */
    private function login(string $email): array
    {
        $user = User::new($email);
        $user->setPassword('hash', 'user-key', false, null);
        $user->save();
        $device = Device::new(Random::uuid(), $user->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);

        return [$user, ['Authorization' => 'Bearer '.LoginClaims::default($device, $user, AuthMethod::Password, 'cli', $jwt)->token($jwt)]];
    }

    private function string(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }
}
