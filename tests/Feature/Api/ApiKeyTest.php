<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Device;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\OrganizationApiKey;
use App\Models\User;
use App\Support\Random;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * Organization API keys, the login with them (src/api/identity.rs) and the
 * directory import of the public API (src/api/core/public.rs).
 */
class ApiKeyTest extends TestCase
{
    use UsesVaultwardenDatabase;

    /**
     * @var array<string, string>
     */
    private array $owner;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000', 'ORG_GROUPS_ENABLED' => 'true'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');

        [, $this->owner] = $this->login('owner@example.com');
    }

    public function test_api_key_login_and_directory_import(): void
    {
        $org = $this->string($this->postJson('/api/organizations', ['billingEmail' => 'billing@example.com', 'collectionName' => '2.first',
            'key' => '4.owner-key', 'name' => '2.org', 'planType' => 0], $this->owner)->json('id'));
        [$known] = $this->login('known@example.com');

        $this->postJson("/api/organizations/{$org}/api-key", ['masterPasswordHash' => 'wrong'], $this->owner)->assertStatus(400)->assertJsonPath('message', 'Invalid password');
        $key = $this->string($this->postJson("/api/organizations/{$org}/api-key", ['masterPasswordHash' => 'hash'], $this->owner)
            ->assertStatus(200)->assertJsonPath('object', 'apiKey')->json('apiKey'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{30}$/', $key);
        $this->postJson("/api/organizations/{$org}/api-key", ['masterPasswordHash' => 'hash'], $this->owner)->assertJsonPath('apiKey', $key);

        $login = ['grant_type' => 'client_credentials', 'client_id' => "organization.{$org}", 'client_secret' => $key, 'scope' => 'api.organization'];
        $token = $this->string($this->postForm($login)->assertStatus(200)
            ->assertJsonPath('expires_in', 3600)->assertJsonPath('scope', 'api.organization')->json('access_token'));
        $this->postForm([...$login, 'client_secret' => 'wrong'])->assertStatus(400)->assertJsonPath('message', 'Incorrect client_secret');
        $public = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/public/organization/import', ['members' => [], 'groups' => [], 'overwriteExisting' => false], $this->owner)->assertStatus(401);
        $this->postJson('/api/public/organization/import', ['members' => [
            ['email' => 'KNOWN@example.com', 'externalId' => 'ext-known', 'deleted' => false],
            ['email' => 'new@example.com', 'externalId' => 'ext-new', 'deleted' => false],
        ], 'groups' => [['name' => 'Staff', 'externalId' => 'grp', 'memberExternalIds' => ['ext-known', 'ext-unknown']]], 'overwriteExisting' => false], $public)->assertStatus(200);

        // Without mail, users with a password join as accepted, others are invited to register.
        $this->assertSame(Membership::STATUS_ACCEPTED, Membership::findByUserAndOrg($known->uuid, $org)?->status);
        $new = User::findByMail('new@example.com');
        $this->assertSame(Membership::STATUS_INVITED, Membership::findByUserAndOrg((string) $new?->uuid, $org)?->status);
        $this->assertNotNull(Invitation::findByMail('new@example.com'));
        $group = Group::findByExternalIdAndOrg('grp', $org);
        $this->assertSame('Staff', $group?->name);
        $this->get("/api/organizations/{$org}/groups/{$group->uuid}/users", $this->owner)->assertJsonCount(1);

        // Overwriting removes the members the directory no longer lists, but no owners.
        $this->postJson('/api/public/organization/import', ['members' => [['email' => 'known@example.com', 'externalId' => 'ext-known', 'deleted' => false]],
            'groups' => [], 'overwriteExisting' => true], $public)->assertStatus(200);
        $this->assertNull(Membership::findByUserAndOrg((string) $new?->uuid, $org));
        $this->assertSame(2, Membership::query()->where('org_uuid', $org)->count());

        // Rotating keeps the key's id, so issued tokens stay valid.
        $this->postJson("/api/organizations/{$org}/rotate-api-key", ['masterPasswordHash' => 'hash'], $this->owner)->assertStatus(200);
        $this->assertNotSame($key, OrganizationApiKey::findByOrgUuid($org)?->api_key);
        $this->postJson('/api/public/organization/import', ['members' => [], 'groups' => [], 'overwriteExisting' => false], $public)->assertStatus(200);
        $this->postForm($login)->assertStatus(400);
    }

    /**
     * @param  array<string, string>  $fields
     * @return TestResponse<Response>
     */
    private function postForm(array $fields): TestResponse
    {
        return $this->call('POST', '/identity/connect/token', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: http_build_query($fields));
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
