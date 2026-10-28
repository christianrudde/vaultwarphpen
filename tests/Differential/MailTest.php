<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * Flows that send mail, on the pair of servers with SMTP sinks: the mails
 * themselves are compared, and their tokens carry the flows on.
 */
class MailTest extends DifferentialTestCase
{
    #[Parity('route:POST /api/organizations/{org_id}/users/{member_id}/accept')]
    #[Parity('route:POST /api/organizations/{org_id}/users/invite')]
    #[Parity('route:POST /api/organizations/{org_id}/users/reinvite')]
    #[Parity('route:POST /api/organizations/{org_id}/users/{member_id}/reinvite')]
    #[Parity('route:POST /api/organizations/{org_id}/users/confirm')]
    #[Parity('route:POST /api/organizations/{org_id}/users/{member_id}/confirm')]
    #[Parity('template:email/send_org_invite*')]
    #[Parity('template:email/invite_accepted*')]
    #[Parity('template:email/invite_confirmed*')]
    #[Parity('template:email/send_2fa_removed_from_org*')]
    #[Parity('template:email/new_device_logged_in*')]
    #[Parity('template:email/email_*')]
    #[Parity('config:_ENABLE_SMTP')]
    #[Parity('config:SMTP_HOST')]
    #[Parity('config:SMTP_PORT')]
    #[Parity('config:SMTP_SECURITY')]
    #[Parity('config:SMTP_FROM')]
    #[Parity('config:SMTP_TIMEOUT')]
    public function test_invitations_by_mail(): void
    {
        $this->assertSameMailFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'mail-owner@example.com');
            [$member] = CipherTest::session($server, 'mail-member@example.com');
            $org = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            $users = "/api/organizations/{$org}/users";
            $steps = [];

            $steps['new device mail'] = $server->mail('mail-member@example.com')->exchange();
            $steps['invite'] = $server->json('POST', "{$users}/invite", ['emails' => ['mail-member@example.com', 'mail-new@example.com'], 'groups' => [], 'type' => 2], $owner);
            $steps['invitation mail'] = $server->mail('mail-member@example.com')->exchange();
            $steps['invitation mail to a new user'] = $server->mail('mail-new@example.com')->exchange();
            $steps['members'] = $members = $server->send('GET', $users, $owner);
            $ids = OrganizationTest::memberIds($members);
            $memberId = $ids['mail-member@example.com'];
            $newId = $ids['mail-new@example.com'];
            $token = $server->mailedToken('mail-member@example.com');

            $steps['accept with another account'] = $server->json('POST', "{$users}/{$memberId}/accept", ['token' => $token], $owner);
            $steps['accept for another member'] = $server->json('POST', "{$users}/{$newId}/accept", ['token' => $token], $member);
            $steps['accept with a bad token'] = $server->json('POST', "{$users}/{$memberId}/accept", ['token' => 'nope'], $member);
            $steps['accept without token'] = $server->json('POST', "{$users}/{$memberId}/accept", [], $member);
            $steps['accept with an invalid id'] = $server->json('POST', "{$users}/nope/accept", ['token' => $token], $member);
            $steps['accept'] = $server->json('POST', "{$users}/{$memberId}/accept", ['token' => $token], $member);
            $steps['accepted mail'] = $server->mail('mail-owner@example.com')->exchange();
            $steps['accept again'] = $server->json('POST', "{$users}/{$memberId}/accept", ['token' => $token], $member);

            $steps['reinvite'] = $server->send('POST', "{$users}/{$newId}/reinvite", $owner);
            $steps['reinvitation mail'] = $server->mail('mail-new@example.com')->exchange();
            $steps['reinvite the accepted'] = $server->send('POST', "{$users}/{$memberId}/reinvite", $owner);
            $steps['bulk reinvite'] = $server->json('POST', "{$users}/reinvite", ['ids' => [$newId, $memberId]], $owner);
            $steps['confirm'] = $server->json('POST', "{$users}/{$memberId}/confirm", ['key' => '4.member-key'], $owner);
            $steps['confirmed mail'] = $server->mail('mail-member@example.com')->exchange();
            $steps['member profile'] = OrganizationTest::sortedProfile($server->send('GET', '/api/accounts/profile', $member));

            $steps['two-factor policy'] = $server->json('PUT', "/api/organizations/{$org}/policies/0", ['policy' => ['enabled' => true, 'data' => null]], $owner);
            $steps['two-factor mail'] = $server->mail('mail-member@example.com')->exchange();
            $steps['members at last'] = $server->send('GET', $users, $owner);

            return $steps;
        });
    }

    #[Parity('template:email/admin_account_recovery*')]
    public function test_account_recovery_by_mail(): void
    {
        $this->assertSameMailFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'recover-owner@example.com');
            [$member] = CipherTest::session($server, 'recover-member@example.com');
            $org = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            $memberId = self::addMember($server, $owner, $member, $org, 'recover-member@example.com');
            $memberUserId = $server->send('GET', '/api/accounts/profile', $member)->jsonString('id');
            $server->json('PUT', "/api/organizations/{$org}/policies/8", ['policy' => ['enabled' => true, 'data' => ['autoEnrollEnabled' => false]]], $owner);
            $users = "/api/organizations/{$org}/users";
            $kdf = ['kdf' => 0, 'kdfIterations' => 600000];
            $steps = [];

            $steps['enroll'] = $server->json('PUT', "{$users}/{$memberUserId}/reset-password-enrollment", ['resetPasswordKey' => '4.reset', 'masterPasswordHash' => 'client-hash'], $member);
            $steps['details'] = $server->send('GET', "{$users}/{$memberId}/reset-password-details", $owner);
            $steps['recover without data'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['resetMasterPassword' => true], $owner);
            $steps['recover'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['resetMasterPassword' => true, 'newMasterPasswordHash' => 'new-hash', 'key' => '2.new-key'], $owner);
            $steps['recovery mail'] = $server->mail('recover-member@example.com')->exchange();
            $steps['old session'] = $server->send('GET', '/api/accounts/profile', $member);
            $steps['old password'] = $server->form('/identity/connect/token', LoginTest::loginFields('recover-member@example.com'));
            $steps['new password'] = $server->form('/identity/connect/token', LoginTest::loginFields('recover-member@example.com', 'new-hash'));

            $authentication = ['salt' => 'recover-member@example.com', 'kdf' => $kdf, 'masterPasswordAuthenticationHash' => 'newer-hash'];
            $unlock = ['salt' => 'recover-member@example.com', 'kdf' => $kdf, 'masterKeyWrappedUserKey' => '2.newer-key'];
            $steps['recover with a wrong salt'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['resetMasterPassword' => true,
                'authenticationData' => [...$authentication, 'salt' => 'other@example.com'], 'unlockData' => $unlock], $owner);
            $steps['recover with other KDF settings'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['resetMasterPassword' => true,
                'authenticationData' => [...$authentication, 'kdf' => ['kdf' => 0, 'kdfIterations' => 700000]], 'unlockData' => [...$unlock, 'kdf' => ['kdf' => 0, 'kdfIterations' => 700000]]], $owner);
            $steps['recover with unlock data'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['resetMasterPassword' => true,
                'authenticationData' => $authentication, 'unlockData' => $unlock], $owner);
            $steps['newest password'] = $server->form('/identity/connect/token', LoginTest::loginFields('recover-member@example.com', 'newer-hash'));
            $steps['reset two-factor login'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['resetTwoFactor' => true], $owner);
            $steps['two-factor mail'] = $server->mail('recover-member@example.com')->exchange();

            return $steps;
        });
    }

    public function test_directory_import_by_mail(): void
    {
        $this->assertSameMailFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'ldap-owner@example.com');
            CipherTest::session($server, 'ldap-known@example.com');
            $org = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            $key = $server->json('POST', "/api/organizations/{$org}/api-key", ['masterPasswordHash' => 'client-hash'], $owner)->jsonString('apiKey');
            $token = $server->form('/identity/connect/token', ['grant_type' => 'client_credentials', 'client_id' => "organization.{$org}",
                'client_secret' => $key, 'scope' => 'api.organization'])->jsonString('access_token');
            $steps = [];

            $steps['import'] = $server->json('POST', '/api/public/organization/import', ['members' => [
                ['email' => 'ldap-known@example.com', 'externalId' => 'ext-known', 'deleted' => false],
                ['email' => 'ldap-new@example.com', 'externalId' => 'ext-new', 'deleted' => false],
            ], 'groups' => [], 'overwriteExisting' => false], ['Authorization' => "Bearer {$token}"]);
            $steps['mail to a user'] = $server->mail('ldap-known@example.com')->exchange();
            $steps['mail to a new user'] = $server->mail('ldap-new@example.com')->exchange();
            $steps['members'] = $server->send('GET', "/api/organizations/{$org}/users", $owner);

            return $steps;
        });
    }

    /**
     * Invites by mail, accepts with the mailed token and confirms; returns the membership id.
     *
     * @param  array<string, string>  $owner
     * @param  array<string, string>  $member
     */
    private static function addMember(Server $server, array $owner, array $member, string $org, string $email): string
    {
        $server->json('POST', "/api/organizations/{$org}/users/invite", ['emails' => [$email], 'groups' => [], 'type' => 2], $owner);
        $memberId = OrganizationTest::memberIds($server->send('GET', "/api/organizations/{$org}/users", $owner))[$email];
        $server->json('POST', "/api/organizations/{$org}/users/{$memberId}/accept", ['token' => $server->mailedToken($email)], $member);
        $server->json('POST', "/api/organizations/{$org}/users/{$memberId}/confirm", ['key' => '4.member-key'], $owner);

        return $memberId;
    }
}
