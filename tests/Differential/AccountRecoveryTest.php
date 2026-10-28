<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * Account recovery on servers without mail, which Vaultwarden requires for
 * it: the lookups and checks before that answer alike.
 */
class AccountRecoveryTest extends DifferentialTestCase
{
    private const string UNKNOWN = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    #[Parity('route:PUT /api/organizations/{org_id}/users/{member_id}/recover-account')]
    #[Parity('route:GET /api/organizations/{org_id}/users/{member_id}/reset-password-details')]
    #[Parity('route:PUT /api/organizations/{org_id}/users/{user_id}/reset-password-enrollment')]
    public function test_account_recovery_without_mail(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$owner] = CipherTest::session($server, 'recovery-owner@example.com');
            [$member, $memberUserId] = CipherTest::session($server, 'recovery-member@example.com');
            [$stranger] = CipherTest::session($server, 'recovery-stranger@example.com');
            $org = $server->json('POST', '/api/organizations', OrganizationTest::org(), $owner)->jsonString('id');
            $memberId = OrganizationTest::addMember($server, $owner, $org, 'recovery-member@example.com');
            $server->json('PUT', "/api/organizations/{$org}/policies/8", ['policy' => ['enabled' => true, 'data' => ['autoEnrollEnabled' => false]]], $owner);
            $unknown = self::UNKNOWN;
            $users = "/api/organizations/{$org}/users";
            $steps = [];

            $steps['enroll'] = $server->json('PUT', "{$users}/{$memberUserId}/reset-password-enrollment", ['resetPasswordKey' => '4.reset', 'masterPasswordHash' => 'client-hash'], $member);
            $steps['enroll someone else'] = $server->json('PUT', "{$users}/{$unknown}/reset-password-enrollment", ['resetPasswordKey' => '4.reset'], $member);
            $steps['enroll with an invalid id'] = $server->json('PUT', "{$users}/nope/reset-password-enrollment", ['resetPasswordKey' => '4.reset'], $member);
            $steps['enroll with a bad body'] = $server->json('PUT', "{$users}/{$memberUserId}/reset-password-enrollment", ['resetPasswordKey' => 4], $member);
            $steps['enroll as stranger'] = $server->json('PUT', "{$users}/{$memberUserId}/reset-password-enrollment", ['resetPasswordKey' => '4.reset'], $stranger);
            $steps['withdraw'] = $server->json('PUT', "{$users}/{$memberUserId}/reset-password-enrollment", ['resetPasswordKey' => ''], $member);

            $steps['details'] = $server->send('GET', "{$users}/{$memberId}/reset-password-details", $owner);
            $steps['details of an unknown member'] = $server->send('GET', "{$users}/{$unknown}/reset-password-details", $owner);
            $steps['details as member'] = $server->send('GET', "{$users}/{$memberId}/reset-password-details", $member);

            $steps['recover'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['resetMasterPassword' => true, 'newMasterPasswordHash' => 'new', 'key' => '2.key'], $owner);
            $steps['recover an unknown member'] = $server->json('PUT', "{$users}/{$unknown}/recover-account", ['resetTwoFactor' => true], $owner);
            $steps['recover with a bad body'] = $server->json('PUT', "{$users}/{$unknown}/recover-account", ['resetTwoFactor' => 'yes'], $owner);
            $steps['recover with bad authentication data'] = $server->json('PUT', "{$users}/{$memberId}/recover-account", ['authenticationData' => ['salt' => 'x']], $owner);
            $steps['recover with an invalid id'] = $server->json('PUT', "{$users}/nope/recover-account", [], $owner);

            return $steps;
        });
    }
}
