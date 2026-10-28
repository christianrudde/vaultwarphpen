<?php

namespace Tests\Feature\Mail;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Auth\LoginClaims;
use App\Mail\Mail;
use App\Mail\Transport;
use App\Models\Device;
use App\Models\Membership;
use App\Models\User;
use App\Support\Random;
use DateTimeImmutable;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\Fakes\MailTransportFake;
use Tests\TestCase;

/**
 * The mails of src/mail.rs, rendered from the templates and handed to the
 * transport, and the invitation flow they carry.
 */
#[Parity('config:SMTP_EMBED_IMAGES')]
#[Parity('config:SMTP_FROM_NAME')]
#[Parity('config:_SMTP_IMG_SRC')]
class MailTest extends TestCase
{
    use UsesVaultwardenDatabase;

    private MailTransportFake $mails;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000', 'SMTP_HOST' => 'localhost', 'SMTP_FROM' => 'vault@example.com',
            'SMTP_FROM_NAME' => 'Vault', 'DOMAIN' => 'https://vault.example.com'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');
        $this->mails = new MailTransportFake;
        $this->app->instance(Transport::class, $this->mails);
    }

    public function test_every_mail_renders(): void
    {
        $user = User::new('kim@example.com');
        $device = Device::new(Random::uuid(), $user->uuid, 'firefox', 3);
        $date = new DateTimeImmutable('2026-10-08 09:05:07 UTC');
        // Dates are local; TZ makes them the same on every host.
        $this->changeVaultwardenEnvironment(['TZ' => 'Europe/Berlin']);

        Mail::sendPasswordHint('kim@example.com', 'my hint');
        Mail::sendPasswordHint('kim@example.com', null);
        Mail::sendDeleteAccount('kim@example.com', $user->uuid);
        Mail::sendVerifyEmail('kim@example.com', $user->uuid);
        Mail::sendRegisterVerifyEmail('kim@example.com', 'token');
        Mail::sendWelcome('kim@example.com');
        Mail::sendWelcomeMustVerify('kim@example.com', $user->uuid);
        Mail::send2faRemovedFromOrg('kim@example.com', 'Org');
        Mail::sendSingleOrgRemovedFromOrg('kim@example.com', 'Org');
        Mail::sendInvite($user, Random::uuid(), Random::uuid(), 'Org', 'boss@example.com');
        Mail::sendEmergencyAccessInvite('kim@example.com', $user->uuid, Random::uuid(), 'Grantor', 'grantor@example.com');
        Mail::sendEmergencyAccessInviteAccepted('kim@example.com', 'grantee@example.com');
        Mail::sendEmergencyAccessInviteConfirmed('kim@example.com', 'Grantor');
        Mail::sendEmergencyAccessRecoveryApproved('kim@example.com', 'Grantor');
        Mail::sendEmergencyAccessRecoveryInitiated('kim@example.com', 'Grantee', 'View', 7);
        Mail::sendEmergencyAccessRecoveryReminder('kim@example.com', 'Grantee', 'View', '2');
        Mail::sendEmergencyAccessRecoveryRejected('kim@example.com', 'Grantor');
        Mail::sendEmergencyAccessRecoveryTimedOut('kim@example.com', 'Grantee', 'View');
        Mail::sendInviteAccepted('new@example.com', 'kim@example.com', 'Org');
        Mail::sendInviteConfirmed('kim@example.com', 'Org');
        Mail::sendNewDeviceLoggedIn('kim@example.com', '192.0.2.1', $date, $device);
        Mail::sendRecoverTwofactor('kim@example.com', '192.0.2.1', $date);
        Mail::sendIncomplete2faLogin('kim@example.com', '192.0.2.1', $date, 'firefox', 'Firefox');
        Mail::sendToken('kim@example.com', '123456');
        Mail::sendChangeEmail('kim@example.com', '123456');
        Mail::sendChangeEmailExisting('kim@example.com', 'other@example.com');
        Mail::sendChangeEmailInvited('kim@example.com', 'other@example.com');
        Mail::sendSsoChangeEmail('kim@example.com');
        Mail::sendTest('kim@example.com');
        Mail::sendAdminAccountRecovery('kim@example.com', 'Kim', 'Org', true, true, false);
        Mail::sendProtectedActionToken('kim@example.com', '123456');

        $this->assertCount(31, $this->mails->sent);
        foreach ($this->mails->sent as $mail) {
            $this->assertNotSame('', $mail['subject']);
            $this->assertNotSame('', $mail['text']);
            $this->assertStringContainsString('<html', $mail['html']);
        }

        $device = $this->mails->sent[20];
        $this->assertSame('New Device Logged In From Firefox', $device['subject']);
        $this->assertStringContainsString('Date: Thursday, October  8, 2026 at 11:05:07 AM CEST', $device['text']);
        $this->assertStringContainsString('Device Name: Firefox', $device['text']);
    }

    public function test_the_message_is_built_like_lettre_builds_it(): void
    {
        Mail::sendWelcome('kim@example.com');
        $message = $this->mails->last('kim@example.com')['message'];
        $headers = $message->getHeaders();

        $this->assertSame('Vault <vault@example.com>', $headers->get('From')?->getBodyAsString());
        $this->assertMatchesRegularExpression('/^<[0-9a-f-]{36}@example\.com>$/', (string) $headers->get('Message-ID')?->getBodyAsString());
        // Text and HTML as alternatives, the HTML related to the two embedded images.
        $body = $message->getBody();
        $this->assertInstanceOf(AlternativePart::class, $body);
        [$text, $related] = $body->getParts();
        $this->assertSame('plain', $text->getMediaSubtype());
        $this->assertInstanceOf(RelatedPart::class, $related);
        $this->assertCount(3, $related->getParts());
        $this->assertStringContainsString('Content-ID: <logo-gray.png>', $related->getParts()[1]->toString());
        $this->assertStringContainsString('src="cid:logo-gray.png"', $this->mails->last('kim@example.com')['html']);
    }

    public function test_values_lose_their_tags_and_urls_are_encoded_like_upstream(): void
    {
        $user = User::new('a+b@example.com');
        Mail::sendInvite($user, 'org-id', 'member-id', '<b>Big</b> & *small*', null);
        $mail = $this->mails->last('a+b@example.com');

        // Tags go; {{x}} escapes the rest for HTML, in the text mail too, {{{x}}} does not.
        // The URL is built before, so its encoded tags stay.
        $this->assertSame('Join Big & *small*', $mail['subject']);
        $this->assertStringContainsString('join the *Big &amp; *small** organization', $mail['text']);
        $this->assertMatchesRegularExpression(
            '#https://vault\.example\.com/\#/accept-organization/\?email=a%2Bb%40example\.com&organizationName=%3Cb%3EBig%3C%2Fb%3E\+%26\+\*small\*&organizationId=org-id&organizationUserId=member-id&token=[\w.-]+&initOrganization=false&orgUserHasExistingUser=false#',
            $mail['text'],
        );
    }

    public function test_invitations_go_out_by_mail_and_are_accepted_with_the_token(): void
    {
        [$owner, $ownerHeaders] = $this->login('owner@example.com');
        [$member, $memberHeaders] = $this->login('member@example.com');
        $org = $this->postJson('/api/organizations', ['billingEmail' => 'billing@example.com', 'collectionName' => '2.first',
            'key' => '4.owner-key', 'name' => 'Acme', 'planType' => 0], $ownerHeaders)->json('id');
        $this->assertIsString($org);

        $this->postJson("/api/organizations/{$org}/users/invite", ['emails' => ['member@example.com'], 'groups' => [], 'type' => 2], $ownerHeaders)->assertStatus(200);
        $membership = Membership::findByUserAndOrg($member->uuid, $org);
        $this->assertSame(Membership::STATUS_INVITED, $membership?->status);
        $invite = $this->mails->last('member@example.com');
        $this->assertSame('Join Acme', $invite['subject']);
        $token = preg_match('/token=([\w.-]+)/', $invite['text'], $match) === 1 ? $match[1] : '';
        $claims = $this->app->make(Jwt::class)->decode($token, JwtIssuer::Invite);
        $this->assertSame($membership->uuid, $claims['member_id']);
        $this->assertSame('owner@example.com', $claims['invited_by_email']);

        $accept = "/api/organizations/{$org}/users/{$membership->uuid}/accept";
        $this->postJson($accept, ['token' => $token], $ownerHeaders)
            ->assertStatus(400)->assertJsonPath('message', 'Invitation was issued to a different account');
        $this->postJson("/api/organizations/{$org}/users/".Random::uuid().'/accept', ['token' => $token], $memberHeaders)
            ->assertStatus(400)->assertJsonPath('message', 'Error accepting the invitation');
        $this->postJson($accept, ['token' => 'nope'], $memberHeaders)->assertStatus(400)->assertJsonPath('message', 'Token is invalid');
        $this->postJson($accept, ['token' => $token], $memberHeaders)->assertStatus(200);
        $this->assertSame(Membership::STATUS_ACCEPTED, Membership::findByUuid($membership->uuid)?->status);
        $this->assertSame('Invitation to Acme accepted', $this->mails->last('owner@example.com')['subject']);
        $this->postJson($accept, ['token' => $token], $memberHeaders)
            ->assertStatus(400)->assertJsonPath('message', 'User already accepted the invitation');

        $this->postJson("/api/organizations/{$org}/users/{$membership->uuid}/confirm", ['key' => '4.k'], $ownerHeaders)->assertStatus(200);
        $this->assertSame('Invitation to Acme confirmed', $this->mails->last('member@example.com')['subject']);

        // A failed mail undoes the invitation, and a user it created.
        $this->mails->failure = 'SMTP error: down';
        $this->postJson("/api/organizations/{$org}/users/invite", ['emails' => ['new@example.com'], 'groups' => [], 'type' => 2], $ownerHeaders)
            ->assertStatus(400)->assertJsonPath('message', 'Error sending invite: SMTP error: down ');
        $this->assertNull(User::findByMail('new@example.com'));
        $this->assertSame($owner->uuid, Membership::query()->where('org_uuid', $org)->where('atype', Membership::TYPE_OWNER)->value('user_uuid'));
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
}
