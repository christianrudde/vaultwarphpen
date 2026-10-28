<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Device;
use App\Models\User;
use App\Support\Random;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * ENFORCE_SINGLE_ORG_WITH_RESET_PW_POLICY: account recovery needs the single
 * organization policy, which then cannot be disabled.
 */
#[Parity('config:ENFORCE_SINGLE_ORG_WITH_RESET_PW_POLICY')]
class SingleOrgResetPolicyTest extends TestCase
{
    use UsesVaultwardenDatabase;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000', 'ENFORCE_SINGLE_ORG_WITH_RESET_PW_POLICY' => 'true'];
    }

    public function test_account_recovery_requires_the_single_organization_policy(): void
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
        $policy = static fn (bool $enabled): array => ['policy' => ['enabled' => $enabled, 'data' => null]];

        $this->putJson("/api/organizations/{$org}/policies/8", $policy(true), $auth)
            ->assertStatus(400)->assertJsonPath('message', 'Single Organization policy is not enabled. It is mandatory for this policy to be enabled.');
        $this->putJson("/api/organizations/{$org}/policies/3", $policy(true), $auth)->assertStatus(200);
        $this->putJson("/api/organizations/{$org}/policies/8", $policy(true), $auth)->assertStatus(200);
        $this->putJson("/api/organizations/{$org}/policies/3", $policy(false), $auth)
            ->assertStatus(400)->assertJsonPath('message', 'Account recovery policy is enabled. It is not allowed to disable this policy.');
        $this->putJson("/api/organizations/{$org}/policies/8", $policy(false), $auth)->assertStatus(200);
        $this->putJson("/api/organizations/{$org}/policies/3", $policy(false), $auth)->assertStatus(200);
    }
}
