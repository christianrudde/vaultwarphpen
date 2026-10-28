<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Auth\LoginClaims;
use App\Models\Cipher;
use App\Models\Device;
use App\Models\EmergencyAccess;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\TwoFactor;
use App\Models\User;
use App\Support\Random;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * Emergency access without mail (src/api/core/emergency_access.rs): invited
 * users who exist accept right away. The mails and the jobs are tested in
 * EmergencyAccessMailTest.
 */
class EmergencyAccessTest extends TestCase
{
    use UsesVaultwardenDatabase;

    /**
     * @var array<string, string>
     */
    private array $grantor;

    /**
     * @var array<string, string>
     */
    private array $grantee;

    private User $grantorUser;

    private User $granteeUser;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');

        [$this->grantorUser, $this->grantor] = $this->login('grantor@example.com', 'Grantor');
        [$this->granteeUser, $this->grantee] = $this->login('grantee@example.com', 'Grantee');
    }

    #[Parity('route:POST /api/emergency-access/invite')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/reinvite')]
    #[Parity('route:GET /api/emergency-access/trusted')]
    #[Parity('route:GET /api/emergency-access/granted')]
    #[Parity('route:GET /api/emergency-access/{emer_id}')]
    public function test_invitations(): void
    {
        $invite = ['email' => 'Grantee@Example.com', 'type' => 'View', 'waitTimeDays' => 7];
        $this->postJson('/api/emergency-access/invite', [...$invite, 'waitTimeDays' => 0], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'Emergency access wait time is out-of-bounds!');
        $this->postJson('/api/emergency-access/invite', [...$invite, 'type' => 2], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid emergency access type.');
        $this->postJson('/api/emergency-access/invite', [...$invite, 'type' => 1.5], $this->grantor)->assertStatus(422);
        $this->postJson('/api/emergency-access/invite', [...$invite, 'email' => 'GRANTOR@example.com'], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'You can not set yourself as an emergency contact.');

        // Without mail, an existing user accepts right away.
        $this->postJson('/api/emergency-access/invite', $invite, $this->grantor)->assertStatus(200)->assertContent('');
        $this->postJson('/api/emergency-access/invite', $invite, $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'Grantee user already invited: grantee@example.com');
        $access = EmergencyAccess::query()->sole();
        $this->assertSame(EmergencyAccess::STATUS_ACCEPTED, $access->status);
        $this->assertSame($this->granteeUser->uuid, $access->grantee_uuid);
        $this->assertNull($access->email);

        $this->get('/api/emergency-access/trusted', $this->grantor)->assertStatus(200)->assertExactJson(['continuationToken' => null, 'object' => 'list', 'data' => [[
            'avatarColor' => null, 'email' => 'grantee@example.com', 'granteeId' => $this->granteeUser->uuid, 'id' => $access->uuid,
            'name' => 'Grantee', 'object' => 'emergencyAccessGranteeDetails', 'status' => 1, 'type' => 0, 'waitTimeDays' => 7,
        ]]]);
        $this->get('/api/emergency-access/granted', $this->grantee)->assertStatus(200)->assertExactJson(['continuationToken' => null, 'object' => 'list', 'data' => [[
            'avatarColor' => null, 'email' => 'grantor@example.com', 'grantorId' => $this->grantorUser->uuid, 'id' => $access->uuid,
            'name' => 'Grantor', 'object' => 'emergencyAccessGrantorDetails', 'status' => 1, 'type' => 0, 'waitTimeDays' => 7,
        ]]]);
        $this->get("/api/emergency-access/{$access->uuid}", $this->grantor)->assertStatus(200)->assertJsonPath('granteeId', $this->granteeUser->uuid);
        $this->get("/api/emergency-access/{$access->uuid}", $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->get('/api/emergency-access/nope', $this->grantor)->assertStatus(404);
        $this->postJson("/api/emergency-access/{$access->uuid}/reinvite", [], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'The grantee user is already accepted or confirmed for emergency access');

        // An unknown email gets an invited user and an invitation.
        $this->postJson('/api/emergency-access/invite', ['email' => 'new@example.com', 'type' => 1, 'waitTimeDays' => 90], $this->grantor)->assertStatus(200);
        $newUser = User::findByMail('new@example.com');
        $this->assertNotNull($newUser);
        $this->assertSame('', $newUser->password_hash);
        $this->assertNotNull(Invitation::findByMail('new@example.com'));
        $invited = EmergencyAccess::findInvitedByGranteeEmail('new@example.com');
        $this->assertNotNull($invited);
        $this->assertNull($invited->grantee_uuid);
        $this->assertSame([1, 0], self::column($this->get('/api/emergency-access/trusted', $this->grantor), 'data', 'status'));

        // Reinviting renews a lost invitation.
        Invitation::take('new@example.com');
        $this->postJson("/api/emergency-access/{$invited->uuid}/reinvite", [], $this->grantor)->assertStatus(200);
        $this->assertNotNull(Invitation::findByMail('new@example.com'));
        $this->postJson("/api/emergency-access/{$invited->uuid}/reinvite", [], $this->grantee)
            ->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');

        // Listing drops invitations whose user is gone.
        User::query()->where('uuid', $newUser->uuid)->delete();
        $this->assertSame([1], self::column($this->get('/api/emergency-access/trusted', $this->grantor), 'data', 'status'));
        $this->assertNull(EmergencyAccess::findInvitedByGranteeEmail('new@example.com'));
    }

    /**
     * Settings change before the first request, which resolves the controller.
     */
    #[Parity('config:INVITATIONS_ALLOWED')]
    public function test_invitations_without_invitations_allowed(): void
    {
        $this->changeVaultwardenEnvironment(['INVITATIONS_ALLOWED' => 'false']);
        $this->postJson('/api/emergency-access/invite', ['email' => "o'neil@example.com", 'type' => 0, 'waitTimeDays' => 1], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', "Grantee user does not exist: o\\'neil@example.com");
    }

    #[Parity('config:SIGNUPS_DOMAINS_WHITELIST')]
    public function test_invitations_outside_the_allowed_domains(): void
    {
        $this->changeVaultwardenEnvironment(['SIGNUPS_DOMAINS_WHITELIST' => 'example.org']);
        $this->postJson('/api/emergency-access/invite', ['email' => 'someone@example.com', 'type' => 0, 'waitTimeDays' => 1], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'Email domain not eligible for invitations');
        $this->assertNull(User::findByMail('someone@example.com'));
    }

    #[Parity('route:PUT /api/emergency-access/{emer_id}')]
    #[Parity('route:POST /api/emergency-access/{emer_id}')]
    #[Parity('route:DELETE /api/emergency-access/{emer_id}')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/delete')]
    public function test_updates_and_deletions(): void
    {
        $id = $this->accept();
        $this->putJson("/api/emergency-access/{$id}", ['type' => 'Takeover', 'waitTimeDays' => 3, 'keyEncrypted' => '4.key'], $this->grantor)
            ->assertStatus(200)->assertExactJson(['id' => $id, 'object' => 'emergencyAccess', 'status' => 1, 'type' => 1, 'waitTimeDays' => 3]);
        $this->postJson("/api/emergency-access/{$id}", ['type' => '0', 'waitTimeDays' => 4], $this->grantor)
            ->assertStatus(200)->assertJsonPath('type', 0)->assertJsonPath('waitTimeDays', 4);
        $this->assertSame('4.key', EmergencyAccess::findByUuidAndGrantorUuid($id, $this->grantorUser->uuid)?->key_encrypted);
        $this->postJson("/api/emergency-access/{$id}", ['type' => 0, 'waitTimeDays' => 91], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'Emergency access wait time is out-of-bounds!');
        $this->postJson("/api/emergency-access/{$id}", ['type' => 0, 'waitTimeDays' => 1], $this->grantee)
            ->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}", ['type' => 0], $this->grantor)->assertStatus(422);

        // Either side may end it.
        $this->delete('/api/emergency-access/'.Random::uuid(), [], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/delete", [], $this->grantee)->assertStatus(200)->assertContent('');
        $this->assertNull(EmergencyAccess::findByUuidAndGrantorUuid($id, $this->grantorUser->uuid));
        $id = $this->accept();
        $this->delete("/api/emergency-access/{$id}", [], $this->grantor)->assertStatus(200);
        $this->assertNull(EmergencyAccess::findByUuidAndGrantorUuid($id, $this->grantorUser->uuid));
    }

    #[Parity('route:POST /api/emergency-access/{emer_id}/confirm')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/initiate')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/approve')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/reject')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/view')]
    public function test_viewing(): void
    {
        $id = $this->accept();
        $this->postJson("/api/emergency-access/{$id}/initiate", [], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/confirm", ['key' => '4.key'], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/confirm", [], $this->grantor)->assertStatus(422);
        $this->postJson("/api/emergency-access/{$id}/confirm", ['key' => '4.key'], $this->grantor)
            ->assertStatus(200)->assertExactJson(['id' => $id, 'object' => 'emergencyAccess', 'status' => 2, 'type' => 0, 'waitTimeDays' => 7]);
        $this->postJson("/api/emergency-access/{$id}/confirm", ['key' => '4.key'], $this->grantor)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');

        $this->postJson("/api/emergency-access/{$id}/approve", [], $this->grantor)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/reject", [], $this->grantor)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/initiate", [], $this->grantor)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/initiate", [], $this->grantee)->assertStatus(200)->assertJsonPath('status', 3);
        $access = EmergencyAccess::findByUuidAndGranteeUuid($id, $this->granteeUser->uuid);
        $this->assertNotNull($access?->recovery_initiated_at);
        $this->assertEquals($access->recovery_initiated_at, $access->last_notification_at);
        $this->postJson("/api/emergency-access/{$id}/view", [], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');

        // A rejection goes back to confirmed.
        $this->postJson("/api/emergency-access/{$id}/reject", [], $this->grantor)->assertStatus(200)->assertJsonPath('status', 2);
        $this->postJson("/api/emergency-access/{$id}/initiate", [], $this->grantee)->assertStatus(200);
        $this->postJson("/api/emergency-access/{$id}/approve", [], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/approve", [], $this->grantor)->assertStatus(200)->assertJsonPath('status', 4);

        // The grantor's own ciphers, without those of organizations.
        $cipher = Cipher::new(1, '2.login');
        $cipher->user_uuid = $this->grantorUser->uuid;
        $cipher->save();
        $view = $this->postJson("/api/emergency-access/{$id}/view", [], $this->grantee)->assertStatus(200);
        $this->assertSame(['ciphers', 'keyEncrypted', 'object'], array_keys((array) $view->json()));
        $this->assertSame('4.key', $view->json('keyEncrypted'));
        $this->assertSame('emergencyAccessView', $view->json('object'));
        $this->assertSame([$cipher->uuid], self::column($view, 'ciphers', 'id'));
        $this->postJson("/api/emergency-access/{$id}/takeover", [], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');

        // Even an approved request can be rejected.
        $this->postJson("/api/emergency-access/{$id}/reject", [], $this->grantor)->assertStatus(200)->assertJsonPath('status', 2);
        $this->postJson("/api/emergency-access/{$id}/view", [], $this->grantee)->assertStatus(400);
    }

    #[Parity('route:POST /api/emergency-access/{emer_id}/takeover')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/password')]
    #[Parity('route:GET /api/emergency-access/{emer_id}/policies')]
    public function test_takeover(): void
    {
        $id = $this->accept(type: 1);
        $this->postJson("/api/emergency-access/{$id}/confirm", ['key' => '4.key'], $this->grantor)->assertStatus(200);
        $this->postJson("/api/emergency-access/{$id}/initiate", [], $this->grantee)->assertStatus(200);
        $this->get("/api/emergency-access/{$id}/policies", $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson("/api/emergency-access/{$id}/approve", [], $this->grantor)->assertStatus(200);

        $this->postJson("/api/emergency-access/{$id}/takeover", [], $this->grantee)->assertStatus(200)->assertExactJson([
            'kdf' => 0, 'kdfIterations' => 600000, 'kdfMemory' => null, 'kdfParallelism' => null, 'keyEncrypted' => '4.key',
            'object' => 'emergencyAccessTakeover', 'salt' => 'grantor@example.com',
        ]);
        $this->postJson("/api/emergency-access/{$id}/view", [], $this->grantee)->assertStatus(400);

        // The grantor owns one organization and is a member of another.
        $own = $this->postJson('/api/organizations', ['billingEmail' => 'billing@example.com', 'collectionName' => '2.first',
            'key' => '4.owner-key', 'name' => '2.org', 'planType' => 0], $this->grantor)->assertStatus(200)->json('id');
        $this->assertIsString($own);
        $other = Organization::new('2.other', 'billing@example.com', null, null);
        $other->save();
        $member = Membership::new($this->grantorUser->uuid, $other->uuid, null);
        $member->status = Membership::STATUS_CONFIRMED;
        $member->save();
        $this->putJson("/api/organizations/{$own}/policies/1", ['policy' => ['enabled' => true, 'data' => ['minLength' => 12]]], $this->grantor)->assertStatus(200);
        TwoFactor::new($this->grantorUser->uuid, TwoFactor::TYPE_AUTHENTICATOR, 'SECRET')->save();

        $policies = $this->get("/api/emergency-access/{$id}/policies", $this->grantee)->assertStatus(200);
        $this->assertSame([1], self::column($policies, 'data', 'type'));
        $this->assertSame(['continuationToken', 'data', 'object'], array_keys((array) $policies->json()));

        $this->postJson("/api/emergency-access/{$id}/password", ['newMasterPasswordHash' => 'new-hash'], $this->grantee)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid request!');
        $kdf = ['kdf' => 0, 'kdfIterations' => 600000];
        $authentication = ['salt' => 'grantor@example.com', 'kdf' => $kdf, 'masterPasswordAuthenticationHash' => 'newer-hash'];
        $unlock = ['salt' => 'grantor@example.com', 'kdf' => $kdf, 'masterKeyWrappedUserKey' => '2.newer-key'];
        $this->postJson("/api/emergency-access/{$id}/password", ['authenticationData' => [...$authentication, 'kdf' => ['kdf' => 0, 'kdfIterations' => 700000]],
            'unlockData' => [...$unlock, 'kdf' => ['kdf' => 0, 'kdfIterations' => 700000]]], $this->grantee)
            ->assertStatus(400)->assertJsonPath('message', 'KDF settings do not match the grantor account');
        $this->postJson("/api/emergency-access/{$id}/password", ['authenticationData' => [...$authentication, 'salt' => 'x'], 'unlockData' => $unlock], $this->grantee)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid master password salt');
        $this->postJson("/api/emergency-access/{$id}/password", ['authenticationData' => ['salt' => 'x']], $this->grantee)->assertStatus(422);

        $this->postJson("/api/emergency-access/{$id}/password", ['newMasterPasswordHash' => 'new-hash', 'key' => '2.new-key'], $this->grantee)
            ->assertStatus(200)->assertContent('');
        $grantor = User::findByUuid($this->grantorUser->uuid);
        $this->assertTrue($grantor?->checkValidPassword('new-hash'));
        $this->assertSame('2.new-key', $grantor->akey);
        $this->assertNotSame($this->grantorUser->security_stamp, $grantor->security_stamp);
        $this->assertTrue(TwoFactor::findByUser($grantor->uuid)->isEmpty());
        $this->assertSame([$own], Membership::findAnyStateByUser($grantor->uuid)->pluck('org_uuid')->all());

        $this->postJson("/api/emergency-access/{$id}/password", ['authenticationData' => $authentication, 'unlockData' => $unlock], $this->grantee)->assertStatus(200);
        $this->assertTrue(User::findByUuid($grantor->uuid)?->checkValidPassword('newer-hash'));
    }

    #[Parity('config:EMERGENCY_ACCESS_ALLOWED')]
    public function test_disabled(): void
    {
        $this->changeVaultwardenEnvironment(['EMERGENCY_ACCESS_ALLOWED' => 'false']);
        $access = EmergencyAccess::new($this->grantorUser->uuid, 'grantee@example.com', EmergencyAccess::STATUS_INVITED, EmergencyAccess::TYPE_TAKEOVER, 7);
        $access->acceptInvite($this->granteeUser->uuid, 'grantee@example.com');
        $id = $access->uuid;

        $this->get('/api/emergency-access/trusted', $this->grantor)->assertStatus(200)->assertExactJson(['continuationToken' => null, 'data' => [], 'object' => 'list']);
        $this->get('/api/emergency-access/granted', $this->grantee)->assertStatus(200)->assertJsonPath('data', []);
        $this->postJson('/api/emergency-access/invite', ['email' => 'x@example.com', 'type' => 0, 'waitTimeDays' => 1], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'Emergency access is not enabled.');
        // Bodies are read first.
        $this->postJson('/api/emergency-access/invite', [], $this->grantor)->assertStatus(422);
        foreach (['get' => '', 'post' => '/reinvite', 'delete' => ''] as $method => $suffix) {
            $this->json(strtoupper($method), "/api/emergency-access/{$id}{$suffix}", [], $this->grantor)
                ->assertStatus(400)->assertJsonPath('message', 'Emergency access is not enabled.');
        }
        $this->postJson("/api/emergency-access/{$id}/initiate", [], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access is not enabled.');
        // Upstream does not check the setting here.
        $this->get("/api/emergency-access/{$id}/policies", $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
    }

    public function test_invitation_tokens_need_every_claim(): void
    {
        $id = $this->accept();
        $jwt = $this->app->make(Jwt::class);
        $token = $jwt->encode(['nbf' => time(), 'exp' => time() + 60, 'iss' => $jwt->issuer(JwtIssuer::EmergencyAccessInvite), 'sub' => $this->granteeUser->uuid,
            'email' => 'grantee@example.com', 'emer_id' => $id, 'grantor_name' => 'Grantor']);

        $message = $this->postJson("/api/emergency-access/{$id}/accept", ['token' => $token], $this->grantee)->assertStatus(400)->json('message');
        $this->assertIsString($message);
        $this->assertStringStartsWith('Error decoding JWT: Error(Json(Error("missing field `grantor_email`", line: 1, column: ', $message);
    }

    /**
     * A column of a list in the response.
     *
     * @param  TestResponse<Response>  $response
     * @return array<int, mixed>
     */
    private static function column(TestResponse $response, string $path, string $key): array
    {
        $list = $response->json($path);
        self::assertIsArray($list);

        return array_column($list, $key);
    }

    /**
     * Invites the grantee, who accepts right away without mail.
     */
    private function accept(int $type = 0): string
    {
        $this->postJson('/api/emergency-access/invite', ['email' => 'grantee@example.com', 'type' => $type, 'waitTimeDays' => 7], $this->grantor)->assertStatus(200);

        return EmergencyAccess::query()->sole()->uuid;
    }

    /**
     * @return array{User, array<string, string>}
     */
    private function login(string $email, string $name): array
    {
        $user = User::new($email, $name);
        $user->setPassword('hash', 'user-key', false, null);
        $user->save();
        $device = Device::new(Random::uuid(), $user->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);

        return [$user, ['Authorization' => 'Bearer '.LoginClaims::default($device, $user, AuthMethod::Password, 'cli', $jwt)->token($jwt)]];
    }
}
