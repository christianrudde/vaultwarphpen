<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Auth\LoginClaims;
use App\Mail\Transport;
use App\Models\Device;
use App\Models\EmergencyAccess;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Random;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\Fakes\MailTransportFake;
use Tests\TestCase;

/**
 * Emergency access with mail: the invitation is accepted with the mailed
 * token, and each step tells the other side. The jobs that grant requests
 * and remind grantors are tested in tests/Feature/Scheduler.
 */
class EmergencyAccessMailTest extends TestCase
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

        [$this->grantorUser, $this->grantor] = $this->login('grantor@example.com', 'Grantor');
        [$this->granteeUser, $this->grantee] = $this->login('grantee@example.com', 'Grantee');
    }

    #[Parity('route:POST /api/emergency-access/{emer_id}/accept')]
    public function test_invitation_by_mail(): void
    {
        $this->postJson('/api/emergency-access/invite', ['email' => 'grantee@example.com', 'type' => 0, 'waitTimeDays' => 2], $this->grantor)->assertStatus(200);
        $access = EmergencyAccess::query()->sole();
        $this->assertSame(EmergencyAccess::STATUS_INVITED, $access->status);
        $this->assertSame('grantee@example.com', $access->email);
        $mail = $this->mails->last('grantee@example.com');
        $this->assertSame('Emergency access for Grantor', $mail['subject']);
        $token = $this->mailedToken($mail['text']);
        $this->assertStringContainsString("/#/accept-emergency/?id={$access->uuid}&name=Grantor&email=grantee%40example.com&token=", $mail['text']);
        $claims = $this->app->make(Jwt::class)->decode($token, JwtIssuer::EmergencyAccessInvite, fields: Jwt::EMERGENCY_ACCESS_INVITE_CLAIMS);
        $this->assertSame([$this->granteeUser->uuid, $access->uuid, 'Grantor', 'grantor@example.com'], [$claims['sub'], $claims['emer_id'], $claims['grantor_name'], $claims['grantor_email']]);

        // A reinvitation carries the grantor's id as subject, like upstream.
        $this->postJson("/api/emergency-access/{$access->uuid}/reinvite", [], $this->grantor)->assertStatus(200);
        $resent = $this->app->make(Jwt::class)->decode($this->mailedToken($this->mails->last('grantee@example.com')['text']), JwtIssuer::EmergencyAccessInvite);
        $this->assertSame($this->grantorUser->uuid, $resent['sub']);

        $accept = "/api/emergency-access/{$access->uuid}/accept";
        $this->postJson($accept, ['token' => $token], $this->grantor)->assertStatus(400)->assertJsonPath('message', 'Claim email does not match current users email');
        $this->postJson('/api/emergency-access/'.Random::uuid().'/accept', ['token' => $token], $this->grantee)
            ->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');
        $this->postJson($accept, [], $this->grantee)->assertStatus(422);
        // The grantor's name is part of the invitation.
        $this->grantorUser->name = 'Renamed';
        $this->grantorUser->save();
        $this->postJson($accept, ['token' => $token], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access invitation error.');
        $this->grantorUser->name = 'Grantor';
        $this->grantorUser->save();

        Invitation::new('grantee@example.com')->save();
        $this->postJson($accept, ['token' => $token], $this->grantee)->assertStatus(200)->assertContent('');
        $this->assertNull(Invitation::findByMail('grantee@example.com'));
        $this->assertSame(EmergencyAccess::STATUS_ACCEPTED, EmergencyAccess::query()->sole()->status);
        $mail = $this->mails->last('grantor@example.com');
        $this->assertSame('Emergency access contact grantee@example.com accepted', $mail['subject']);
        $this->postJson($accept, ['token' => $token], $this->grantee)->assertStatus(400)->assertJsonPath('message', 'Emergency access not valid.');

        $this->postJson("/api/emergency-access/{$access->uuid}/confirm", ['key' => '4.key'], $this->grantor)->assertStatus(200);
        $this->assertSame('Emergency access contact for Grantor confirmed', $this->mails->last('grantee@example.com')['subject']);
    }

    public function test_invitation_of_a_new_user_by_mail(): void
    {
        $this->postJson('/api/emergency-access/invite', ['email' => 'new@example.com', 'type' => 1, 'waitTimeDays' => 2], $this->grantor)->assertStatus(200);
        $this->assertNull(Invitation::findByMail('new@example.com'));
        $this->assertNotNull(User::findByMail('new@example.com'));
        $this->assertSame('Emergency access for Grantor', $this->mails->last('new@example.com')['subject']);

        // A failing mail leaves the access in place.
        $this->mails->failure = 'SMTP error: unreachable';
        $this->postJson('/api/emergency-access/invite', ['email' => 'other@example.com', 'type' => 1, 'waitTimeDays' => 2], $this->grantor)
            ->assertStatus(400)->assertJsonPath('message', 'SMTP error: unreachable');
        $this->assertNotNull(EmergencyAccess::findInvitedByGranteeEmail('other@example.com'));
    }

    public function test_recovery_mails(): void
    {
        $id = $this->confirmed();
        $this->postJson("/api/emergency-access/{$id}/initiate", [], $this->grantee)->assertStatus(200);
        $mail = $this->mails->last('grantor@example.com');
        $this->assertSame('Emergency access request by Grantee initiated', $mail['subject']);
        $this->assertStringContainsString('Grantee has initiated an emergency access request to View your account.', $mail['text']);
        $this->assertStringContainsString('3 day(s)', $mail['text']);

        $this->postJson("/api/emergency-access/{$id}/approve", [], $this->grantor)->assertStatus(200);
        $this->assertSame('Emergency access request for Grantor approved', $this->mails->last('grantee@example.com')['subject']);
        $this->postJson("/api/emergency-access/{$id}/reject", [], $this->grantor)->assertStatus(200);
        $this->assertSame('Emergency access request to Grantor rejected', $this->mails->last('grantee@example.com')['subject']);
    }

    /**
     * An access confirmed for the grantee, three days of wait time.
     */
    private function confirmed(string $email = 'grantee@example.com'): string
    {
        $grantee = User::findByMail($email) ?? $this->login($email, 'Other')[0];
        $access = EmergencyAccess::new($this->grantorUser->uuid, $email, EmergencyAccess::STATUS_INVITED, EmergencyAccess::TYPE_VIEW, 3);
        $access->acceptInvite($grantee->uuid, $email);
        $access->status = EmergencyAccess::STATUS_CONFIRMED;
        $access->key_encrypted = '4.key';
        $access->save();

        return $access->uuid;
    }

    private function mailedToken(string $text): string
    {
        if (preg_match('/[?&]token=([\w.-]+)/', $text, $match) !== 1) {
            $this->fail('No token in the mail.');
        }

        return $match[1];
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
