<?php

namespace Tests\Differential;

use App\Http\Controllers\TwoFactor\AuthenticatorController;
use App\Support\Base32;
use Tests\Attributes\Parity;

/**
 * Emergency access: without mail, invited users who exist accept right away
 * and new ones on registration; with mail, they accept with the mailed
 * token, and every step mails the other side. The jobs that grant requests
 * after their wait time cannot run here: the shortest wait is a day.
 */
class EmergencyAccessTest extends DifferentialTestCase
{
    private const string UNKNOWN = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    #[Parity('route:POST /api/emergency-access/invite')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/reinvite')]
    #[Parity('route:GET /api/emergency-access/trusted')]
    #[Parity('route:GET /api/emergency-access/granted')]
    #[Parity('route:GET /api/emergency-access/{emer_id}')]
    #[Parity('route:PUT /api/emergency-access/{emer_id}')]
    #[Parity('route:POST /api/emergency-access/{emer_id}')]
    #[Parity('route:DELETE /api/emergency-access/{emer_id}')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/delete')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/confirm')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/initiate')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/approve')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/reject')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/view')]
    public function test_viewing_without_mail(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$grantor, $grantorId] = CipherTest::session($server, 'ea-grantor@example.com');
            [$grantee] = CipherTest::session($server, 'ea-grantee@example.com');
            [$stranger] = CipherTest::session($server, 'ea-stranger@example.com');
            $unknown = self::UNKNOWN;
            $base = '/api/emergency-access';
            $invite = ['email' => 'EA-Grantee@example.com', 'type' => 'View', 'waitTimeDays' => 7];
            $steps = [];

            $steps['nothing trusted'] = $server->send('GET', "{$base}/trusted", $grantor);
            $steps['invite with a long wait'] = $server->json('POST', "{$base}/invite", [...$invite, 'waitTimeDays' => 91], $grantor);
            $steps['invite with a bad type'] = $server->json('POST', "{$base}/invite", [...$invite, 'type' => 'Read'], $grantor);
            $steps['invite with another type number'] = $server->json('POST', "{$base}/invite", [...$invite, 'type' => 2], $grantor);
            $steps['invite with a fractional type'] = $server->json('POST', "{$base}/invite", [...$invite, 'type' => 0.5], $grantor);
            $steps['invite without a wait time'] = $server->json('POST', "{$base}/invite", ['email' => 'ea-grantee@example.com', 'type' => 0], $grantor);
            $steps['invite oneself'] = $server->json('POST', "{$base}/invite", [...$invite, 'email' => 'EA-GRANTOR@example.com'], $grantor);
            $steps['invite without login'] = $server->json('POST', "{$base}/invite", $invite);
            $steps['invite'] = $server->json('POST', "{$base}/invite", $invite, $grantor);
            $steps['invite again'] = $server->json('POST', "{$base}/invite", [...$invite, 'type' => 1], $grantor);
            $steps['trusted'] = $server->send('GET', "{$base}/trusted", $grantor);
            $steps['granted'] = $server->send('GET', "{$base}/granted", $grantee);
            $steps['nothing granted to the grantor'] = $server->send('GET', "{$base}/granted", $grantor);
            $id = $steps['trusted']->jsonString('data.0.id');
            $access = "{$base}/{$id}";

            $steps['get'] = $server->send('GET', $access, $grantor);
            $steps['get as grantee'] = $server->send('GET', $access, $grantee);
            $steps['get an unknown'] = $server->send('GET', "{$base}/{$unknown}", $grantor);
            $steps['get with an invalid id'] = $server->send('GET', "{$base}/nope", $grantor);
            $steps['reinvite the accepted'] = $server->send('POST', "{$access}/reinvite", $grantor);
            $steps['update'] = $server->json('PUT', $access, ['type' => 'Takeover', 'waitTimeDays' => 3, 'keyEncrypted' => '4.early'], $grantor);
            $steps['update by post'] = $server->json('POST', $access, ['type' => '0', 'waitTimeDays' => 2], $grantor);
            $steps['update without a wait time'] = $server->json('POST', $access, ['type' => 0], $grantor);
            $steps['update with a short wait'] = $server->json('POST', $access, ['type' => 0, 'waitTimeDays' => 0], $grantor);
            $steps['update with a bad type'] = $server->json('POST', $access, ['type' => 7, 'waitTimeDays' => 0], $grantor);
            $steps['update as grantee'] = $server->json('POST', $access, ['type' => 0, 'waitTimeDays' => 1], $grantee);
            $steps['update with an invalid id'] = $server->json('POST', "{$base}/nope", ['type' => 0, 'waitTimeDays' => 1], $grantor);

            $steps['initiate before confirmation'] = $server->send('POST', "{$access}/initiate", $grantee);
            $steps['confirm as grantee'] = $server->json('POST', "{$access}/confirm", ['key' => '4.key'], $grantee);
            $steps['confirm without key'] = $server->json('POST', "{$access}/confirm", [], $grantor);
            $steps['confirm'] = $server->json('POST', "{$access}/confirm", ['key' => '4.key'], $grantor);
            $steps['confirm again'] = $server->json('POST', "{$access}/confirm", ['key' => '4.key'], $grantor);
            $steps['approve before initiation'] = $server->send('POST', "{$access}/approve", $grantor);
            $steps['reject before initiation'] = $server->send('POST', "{$access}/reject", $grantor);
            $steps['initiate as grantor'] = $server->send('POST', "{$access}/initiate", $grantor);
            $steps['initiate'] = $server->send('POST', "{$access}/initiate", $grantee);
            $steps['initiate again'] = $server->send('POST', "{$access}/initiate", $grantee);
            $steps['view before approval'] = $server->send('POST', "{$access}/view", $grantee);
            $steps['reject'] = $server->send('POST', "{$access}/reject", $grantor);
            $steps['initiate once more'] = $server->send('POST', "{$access}/initiate", $grantee);
            $steps['approve as grantee'] = $server->send('POST', "{$access}/approve", $grantee);
            $steps['approve an unknown'] = $server->send('POST', "{$base}/{$unknown}/approve", $grantor);
            $steps['approve'] = $server->send('POST', "{$access}/approve", $grantor);
            $steps['approve again'] = $server->send('POST', "{$access}/approve", $grantor);

            $steps['cipher'] = $server->json('POST', '/api/ciphers', OrganizationTest::note($grantorId, '2.note'), $grantor);
            $steps['view'] = $server->send('POST', "{$access}/view", $grantee);
            $steps['view as grantor'] = $server->send('POST', "{$access}/view", $grantor);
            $steps['view with an invalid id'] = $server->send('POST', "{$base}/nope/view", $grantee);
            $steps['take over a view access'] = $server->send('POST', "{$access}/takeover", $grantee);
            $steps['policies of a view access'] = $server->send('GET', "{$access}/policies", $grantee);
            $steps['trusted while approved'] = $server->send('GET', "{$base}/trusted", $grantor);
            $steps['granted while approved'] = $server->send('GET', "{$base}/granted", $grantee);
            $steps['reject the approved'] = $server->send('POST', "{$access}/reject", $grantor);
            $steps['view after rejection'] = $server->send('POST', "{$access}/view", $grantee);

            $steps['delete an unknown'] = $server->send('DELETE', "{$base}/{$unknown}", $grantor);
            $steps['delete as stranger'] = $server->send('DELETE', $access, $stranger);
            $steps['delete with an invalid id'] = $server->send('DELETE', "{$base}/nope", $grantor);
            $steps['delete as grantee'] = $server->send('POST', "{$access}/delete", $grantee);
            $steps['delete again'] = $server->send('DELETE', $access, $grantor);
            $steps['trusted at last'] = $server->send('GET', "{$base}/trusted", $grantor);
            $steps['invite anew'] = $server->json('POST', "{$base}/invite", $invite, $grantor);
            $steps['delete as grantor'] = $server->send('DELETE', "{$base}/".$server->send('GET', "{$base}/trusted", $grantor)->jsonString('data.0.id'), $grantor);

            return $steps;
        });
    }

    #[Parity('route:POST /api/emergency-access/{emer_id}/takeover')]
    #[Parity('route:POST /api/emergency-access/{emer_id}/password')]
    #[Parity('route:GET /api/emergency-access/{emer_id}/policies')]
    #[Parity('route:POST /identity/accounts/register/finish')]
    public function test_takeover_by_a_new_user_without_mail(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$grantor] = CipherTest::session($server, 'ea-takeover-grantor@example.com');
            [$owner] = CipherTest::session($server, 'ea-takeover-owner@example.com');
            $base = '/api/emergency-access';
            $steps = [];

            // An unknown email gets an invited user, who accepts by registering.
            $steps['invite a new user'] = $server->json('POST', "{$base}/invite", ['email' => 'ea-takeover-new@example.com', 'type' => 1, 'waitTimeDays' => 1], $grantor);
            $steps['trusted'] = $trusted = $server->send('GET', "{$base}/trusted", $grantor);
            $id = $trusted->jsonString('data.0.id');
            $access = "{$base}/{$id}";
            $steps['reinvite'] = $server->send('POST', "{$access}/reinvite", $grantor);
            $claims = ['nbf' => time() - 60, 'exp' => time() + 3600, 'iss' => '', 'sub' => $trusted->jsonString('data.0.granteeId'),
                'email' => 'ea-takeover-new@example.com', 'emer_id' => $id, 'grantor_name' => 'ea-takeover-grantor@example.com', 'grantor_email' => 'ea-takeover-grantor@example.com'];
            $register = ['email' => 'ea-takeover-new@example.com', 'acceptEmergencyAccessId' => $id, 'kdf' => 0, 'kdfIterations' => 600000,
                'masterPasswordHash' => 'client-hash', 'key' => 'user-key', 'keys' => ['encryptedPrivateKey' => 'private-key', 'publicKey' => 'public-key']];
            $incomplete = $claims;
            unset($incomplete['grantor_email']);
            $steps['register with an incomplete invitation'] = $server->json('POST', '/identity/accounts/register/finish',
                [...$register, 'acceptEmergencyAccessInviteToken' => $this->signedToken('emergencyaccessinvite', $incomplete)]);
            $steps['register with the invitation'] = $server->json('POST', '/identity/accounts/register/finish',
                [...$register, 'acceptEmergencyAccessInviteToken' => $this->signedToken('emergencyaccessinvite', $claims)]);
            $steps['trusted after registration'] = $server->send('GET', "{$base}/trusted", $grantor);
            $grantee = ['Authorization' => 'Bearer '.$server->form('/identity/connect/token', LoginTest::loginFields('ea-takeover-new@example.com'))->jsonString('access_token'),
                'Accept' => 'application/json'];

            $steps['accept the accepted'] = $server->json('POST', "{$access}/accept", ['token' => $this->signedToken('emergencyaccessinvite', $claims)], $grantee);
            $steps['confirm'] = $server->json('POST', "{$access}/confirm", ['key' => '4.key'], $grantor);
            $steps['initiate'] = $server->send('POST', "{$access}/initiate", $grantee);
            $steps['take over before approval'] = $server->send('POST', "{$access}/takeover", $grantee);
            $steps['policies before approval'] = $server->send('GET', "{$access}/policies", $grantee);
            $steps['approve'] = $server->send('POST', "{$access}/approve", $grantor);

            // The grantor owns an organization with a policy, is a member of another and has two-factor login.
            $own = $server->json('POST', '/api/organizations', OrganizationTest::org(), $grantor)->jsonString('id');
            $server->json('PUT', "/api/organizations/{$own}/policies/2", ['policy' => ['enabled' => true, 'data' => ['minLength' => 10]]], $grantor);
            $other = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            OrganizationTest::addMember($server, $owner, $other, 'ea-takeover-grantor@example.com');
            $setup = $server->json('POST', '/api/two-factor/get-authenticator', ['masterPasswordHash' => 'client-hash'], $grantor);
            $steps['two-factor login'] = $server->json('POST', '/api/two-factor/authenticator', ['key' => $setup->jsonString('authenticator.key'),
                'userVerificationToken' => $setup->jsonString('userVerificationToken'),
                'token' => AuthenticatorController::totp(Base32::decode($setup->jsonString('authenticator.key')) ?? '', time())], $grantor);

            $steps['policies'] = $server->send('GET', "{$access}/policies", $grantee);
            $steps['policies as grantor'] = $server->send('GET', "{$access}/policies", $grantor);
            $steps['policies with an invalid id'] = $server->send('GET', "{$base}/nope/policies", $grantee);
            $steps['take over'] = $server->send('POST', "{$access}/takeover", $grantee);
            $steps['view a takeover access'] = $server->send('POST', "{$access}/view", $grantee);
            $kdf = ['kdf' => 0, 'kdfIterations' => 600000];
            $authentication = ['salt' => 'ea-takeover-grantor@example.com', 'kdf' => $kdf, 'masterPasswordAuthenticationHash' => 'newer-hash'];
            $unlock = ['salt' => 'ea-takeover-grantor@example.com', 'kdf' => $kdf, 'masterKeyWrappedUserKey' => '2.newer-key'];
            $steps['password without data'] = $server->json('POST', "{$access}/password", ['newMasterPasswordHash' => 'new-hash'], $grantee);
            $steps['password with a bad body'] = $server->json('POST', "{$access}/password", ['key' => 5], $grantee);
            $steps['password with incomplete data'] = $server->json('POST', "{$access}/password", ['authenticationData' => ['salt' => 'x']], $grantee);
            $steps['password with a wrong salt'] = $server->json('POST', "{$access}/password", ['authenticationData' => [...$authentication, 'salt' => 'x'], 'unlockData' => $unlock], $grantee);
            $steps['password with other KDF settings'] = $server->json('POST', "{$access}/password", [
                'authenticationData' => [...$authentication, 'kdf' => ['kdf' => 0, 'kdfIterations' => 700000]],
                'unlockData' => [...$unlock, 'kdf' => ['kdf' => 0, 'kdfIterations' => 700000]]], $grantee);
            $steps['password as grantor'] = $server->json('POST', "{$access}/password", ['newMasterPasswordHash' => 'new-hash', 'key' => '2.new-key'], $grantor);
            $steps['password'] = $server->json('POST', "{$access}/password", ['newMasterPasswordHash' => 'new-hash', 'key' => '2.new-key'], $grantee);

            $steps['old session of the grantor'] = $server->send('GET', '/api/accounts/profile', $grantor);
            $steps['old password'] = $server->form('/identity/connect/token', LoginTest::loginFields('ea-takeover-grantor@example.com'));
            $steps['new password without two-factor login'] = $login = $server->form('/identity/connect/token', LoginTest::loginFields('ea-takeover-grantor@example.com', 'new-hash'));
            $grantor = ['Authorization' => 'Bearer '.$login->jsonString('access_token'), 'Accept' => 'application/json'];
            $steps['grantor profile'] = OrganizationTest::sortedProfile($server->send('GET', '/api/accounts/profile', $grantor));
            $steps['members of the other organization'] = $server->send('GET', "/api/organizations/{$other}/users", $owner);
            $steps['password with unlock data'] = $server->json('POST', "{$access}/password", ['authenticationData' => $authentication, 'unlockData' => $unlock], $grantee);
            $steps['newest password'] = $server->form('/identity/connect/token', LoginTest::loginFields('ea-takeover-grantor@example.com', 'newer-hash'));

            return $steps;
        });
    }

    #[Parity('route:POST /api/emergency-access/{emer_id}/accept')]
    #[Parity('template:email/send_emergency_access_invite*')]
    #[Parity('template:email/emergency_access_invite_accepted*')]
    #[Parity('template:email/emergency_access_invite_confirmed*')]
    #[Parity('template:email/emergency_access_recovery_initiated*')]
    #[Parity('template:email/emergency_access_recovery_approved*')]
    #[Parity('template:email/emergency_access_recovery_rejected*')]
    public function test_emergency_access_by_mail(): void
    {
        $this->assertSameMailFlows(function (Server $server): array {
            [$grantor] = CipherTest::session($server, 'ea-mail-grantor@example.com');
            [$grantee, $granteeId] = CipherTest::session($server, 'ea-mail-grantee@example.com');
            $base = '/api/emergency-access';
            $steps = [];

            $steps['invite'] = $server->json('POST', "{$base}/invite", ['email' => 'ea-mail-grantee@example.com', 'type' => 0, 'waitTimeDays' => 1], $grantor);
            $steps['invitation mail'] = $server->mail('ea-mail-grantee@example.com')->exchange();
            $token = $server->mailedToken('ea-mail-grantee@example.com');
            $steps['trusted'] = $trusted = $server->send('GET', "{$base}/trusted", $grantor);
            $steps['nothing granted yet'] = $server->send('GET', "{$base}/granted", $grantee);
            $id = $trusted->jsonString('data.0.id');
            $access = "{$base}/{$id}";
            $steps['reinvite'] = $server->send('POST', "{$access}/reinvite", $grantor);
            $steps['reinvitation mail'] = $server->mail('ea-mail-grantee@example.com')->exchange();

            $forged = ['nbf' => time() - 60, 'exp' => time() + 3600, 'iss' => '', 'sub' => $granteeId, 'email' => 'ea-mail-grantee@example.com',
                'emer_id' => $id, 'grantor_name' => 'Someone else', 'grantor_email' => 'ea-mail-grantor@example.com'];
            $steps['accept with the grantor\'s account'] = $server->json('POST', "{$access}/accept", ['token' => $token], $grantor);
            $steps['accept another access'] = $server->json('POST', "{$base}/".self::UNKNOWN.'/accept', ['token' => $token], $grantee);
            $steps['accept with a bad token'] = $server->json('POST', "{$access}/accept", ['token' => 'nope'], $grantee);
            $steps['accept without token'] = $server->json('POST', "{$access}/accept", [], $grantee);
            $steps['accept with an invalid id'] = $server->json('POST', "{$base}/nope/accept", ['token' => $token], $grantee);
            $steps['accept a forged invitation'] = $server->json('POST', "{$access}/accept", ['token' => $this->signedToken('emergencyaccessinvite', $forged)], $grantee);
            $steps['accept'] = $server->json('POST', "{$access}/accept", ['token' => $token], $grantee);
            $steps['accepted mail'] = $server->mail('ea-mail-grantor@example.com')->exchange();
            $steps['accept again'] = $server->json('POST', "{$access}/accept", ['token' => $token], $grantee);

            $steps['confirm'] = $server->json('POST', "{$access}/confirm", ['key' => '4.key'], $grantor);
            $steps['confirmed mail'] = $server->mail('ea-mail-grantee@example.com')->exchange();
            $steps['initiate'] = $server->send('POST', "{$access}/initiate", $grantee);
            $steps['initiated mail'] = $server->mail('ea-mail-grantor@example.com')->exchange();
            $steps['approve'] = $server->send('POST', "{$access}/approve", $grantor);
            $steps['approved mail'] = $server->mail('ea-mail-grantee@example.com')->exchange();
            $steps['reject'] = $server->send('POST', "{$access}/reject", $grantor);
            $steps['rejected mail'] = $server->mail('ea-mail-grantee@example.com')->exchange();
            $steps['granted'] = $server->send('GET', "{$base}/granted", $grantee);

            // A new user is invited by mail only.
            $steps['invite a new user'] = $server->json('POST', "{$base}/invite", ['email' => 'ea-mail-new@example.com', 'type' => 1, 'waitTimeDays' => 30], $grantor);
            $steps['invitation mail to a new user'] = $server->mail('ea-mail-new@example.com')->exchange();
            $steps['trusted with the new user'] = $server->send('GET', "{$base}/trusted", $grantor);

            return $steps;
        });
    }
}
