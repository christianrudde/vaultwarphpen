<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Mail\Transport;
use App\Models\Device;
use App\Models\Membership;
use App\Models\User;
use App\Support\Random;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\Fakes\MailTransportFake;
use Tests\TestCase;

/**
 * Account recovery (src/api/core/organizations.rs). It needs mail, so SMTP
 * is configured here, with a fake transport.
 */
class AccountRecoveryTest extends TestCase
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

    private MailTransportFake $mails;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000', 'SMTP_HOST' => 'localhost', 'SMTP_FROM' => 'vault@example.com'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');
        $this->mails = new MailTransportFake;
        $this->app->instance(Transport::class, $this->mails);

        [, $this->owner] = $this->login('owner@example.com');
        [$this->memberUser, $this->member] = $this->login('member@example.com');
    }

    public function test_enrollment_and_details(): void
    {
        $org = $this->createOrganization();
        $memberId = $this->addMember($org);
        $enrollment = "/api/organizations/{$org}/users/{$this->memberUser->uuid}/reset-password-enrollment";

        $this->putJson($enrollment, ['resetPasswordKey' => '4.reset', 'masterPasswordHash' => 'hash'], $this->member)
            ->assertStatus(400)->assertJsonPath('message', 'Policy not found');
        $this->putJson("/api/organizations/{$org}/policies/8", ['policy' => ['enabled' => true, 'data' => ['autoEnrollEnabled' => false]]], $this->owner)->assertStatus(200);

        $this->putJson("/api/organizations/{$org}/users/".Random::uuid().'/reset-password-enrollment', ['resetPasswordKey' => '4.reset'], $this->member)
            ->assertStatus(400)->assertJsonPath('message', "User to enroll isn't member of required organization");
        $this->putJson($enrollment, ['resetPasswordKey' => '4.reset', 'masterPasswordHash' => 'wrong'], $this->member)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid password');
        $this->putJson($enrollment, ['resetPasswordKey' => '4.reset', 'masterPasswordHash' => 'hash'], $this->member)->assertStatus(200);
        $this->assertSame('4.reset', Membership::findByUuid($memberId)?->reset_password_key);

        $this->get("/api/organizations/{$org}/users/{$memberId}/reset-password-details", $this->owner)->assertStatus(200)->assertExactJson([
            'encryptedPrivateKey' => null, 'kdf' => 0, 'kdfIterations' => 600000, 'kdfMemory' => null, 'kdfParallelism' => null,
            'masterPasswordSalt' => 'member@example.com', 'object' => 'organizationUserResetPasswordDetails', 'organizationUserId' => $memberId, 'resetPasswordKey' => '4.reset',
        ]);
        $this->get("/api/organizations/{$org}/users/".Random::uuid().'/reset-password-details', $this->owner)
            ->assertStatus(400)->assertJsonPath('message', "User to reset isn't member of required organization");

        // Recovering mails the member first; without the mail nothing changes.
        $recover = "/api/organizations/{$org}/users/{$memberId}/recover-account";
        $this->putJson($recover, ['resetMasterPassword' => true], $this->owner)
            ->assertStatus(422)->assertJsonPath('message', 'Unprocessable request');
        $this->mails->failure = 'SMTP error: unreachable';
        $this->putJson($recover, ['resetMasterPassword' => true, 'newMasterPasswordHash' => 'new', 'key' => '2.key'], $this->owner)
            ->assertStatus(400)->assertJsonPath('message', 'Error sending user reset password email: SMTP error: unreachable');
        $this->assertTrue(User::findByUuid($this->memberUser->uuid)?->checkValidPassword('hash'));
        // Withdrawing needs no password, unless the policy enrolls automatically.
        $this->putJson("/api/organizations/{$org}/policies/8", ['policy' => ['enabled' => true, 'data' => ['autoEnrollEnabled' => true]]], $this->owner)->assertStatus(200);
        $this->putJson($enrollment, ['resetPasswordKey' => ''], $this->member)
            ->assertStatus(400)->assertJsonPath('message', "Reset password can't be withdrawn due to an enterprise policy");
        $this->putJson("/api/organizations/{$org}/policies/8", ['policy' => ['enabled' => true, 'data' => ['autoEnrollEnabled' => false]]], $this->owner)->assertStatus(200);
        $this->putJson($enrollment, ['resetPasswordKey' => ''], $this->member)->assertStatus(200);
        $this->assertNull(Membership::findByUuid($memberId)->reset_password_key);
        $this->putJson($recover, ['resetTwoFactor' => true], $this->owner)
            ->assertStatus(400)->assertJsonPath('message', 'Password reset not or not correctly enrolled');

        // Enrolled again, the recovery goes through; the new security stamp logs the member out.
        $this->putJson($enrollment, ['resetPasswordKey' => '4.reset', 'masterPasswordHash' => 'hash'], $this->member)->assertStatus(200);
        $this->mails->failure = null;
        $this->putJson($recover, ['resetMasterPassword' => true, 'newMasterPasswordHash' => 'new', 'key' => '2.key'], $this->owner)->assertStatus(200);
        $this->assertTrue(User::findByUuid($this->memberUser->uuid)?->checkValidPassword('new'));
        $mail = $this->mails->last('member@example.com');
        $this->assertSame('Admin account recovery from 2.org organization', $mail['subject']);
        $this->assertStringContainsString('The master password for member@example.com has been changed.', $mail['text']);

        $this->get('/api/accounts/profile', $this->member)->assertStatus(401);
    }

    public function test_admins_cannot_recover_owners(): void
    {
        $org = $this->createOrganization();
        $memberId = $this->addMember($org);
        $this->putJson("/api/organizations/{$org}/users/{$memberId}", ['type' => 1, 'collections' => []], $this->owner)->assertStatus(200);
        $this->putJson("/api/organizations/{$org}/policies/8", ['policy' => ['enabled' => true, 'data' => null]], $this->owner)->assertStatus(200);
        $ownerId = Membership::query()->where('org_uuid', $org)->where('atype', Membership::TYPE_OWNER)->value('uuid');
        $this->assertIsString($ownerId);

        $this->get("/api/organizations/{$org}/users/{$ownerId}/reset-password-details", $this->member)
            ->assertStatus(400)->assertJsonPath('message', "No permission to reset this user's password");
        $this->get("/api/organizations/{$org}/users/{$memberId}/reset-password-details", $this->member)->assertStatus(200);
    }

    private function createOrganization(): string
    {
        $id = $this->postJson('/api/organizations', ['billingEmail' => 'billing@example.com', 'collectionName' => '2.first',
            'key' => '4.owner-key', 'name' => '2.org', 'planType' => 0], $this->owner)->assertStatus(200)->json('id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * With mail, invitations are mailed; the member is added directly.
     */
    private function addMember(string $orgId): string
    {
        $member = Membership::new($this->memberUser->uuid, $orgId, null);
        $member->status = Membership::STATUS_CONFIRMED;
        $member->akey = '4.member-key';
        $member->save();

        return $member->uuid;
    }

    /**
     * @return array{User, array<string, string>}
     */
    private function login(string $email): array
    {
        $user = User::new($email);
        $user->setPassword('hash', 'user-key', false, null);
        $user->verified_at = $user->created_at;
        $user->save();
        $device = Device::new(Random::uuid(), $user->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);

        return [$user, ['Authorization' => 'Bearer '.LoginClaims::default($device, $user, AuthMethod::Password, 'cli', $jwt)->token($jwt)]];
    }
}
