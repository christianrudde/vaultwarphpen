<?php

namespace Tests\Feature\Api;

use App\Auth\Sso\AuthenticatedUser;
use App\Models\Casts\NaiveDateTime;
use App\Models\SsoAuth;
use App\Models\SsoUser;
use App\Models\User;
use App\Support\Dates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * The SSO login's records (src/db/models/sso_auth.rs and SsoUser) on every
 * database, and what the routes answer without SSO. The login itself is
 * compared with Vaultwarden in tests/Differential/SsoTest.
 */
class SsoTest extends TestCase
{
    use UsesVaultwardenDatabase;

    #[DataProvider('drivers')]
    #[Parity('table:sso_auth')]
    #[Parity('table:sso_users')]
    public function test_logins_in_progress_and_linked_users(string $driver): void
    {
        $this->useDatabase($driver);

        $auth = SsoAuth::new('state', 'challenge', 'nonce', 'https://vault.example.com/sso-connector.html', 'binding');
        $auth->save();
        $auth->code_response = 'code';
        $auth->setCodeResponseError('access_denied', null);
        $auth->save();
        $found = SsoAuth::findByCode('code');
        $this->assertNotNull($found);
        $this->assertSame(['state', ['access_denied', null]], [$found->state, $found->codeResponseError()]);

        $user = new AuthenticatedUser('refresh', 'access', 60, 'https://idp/sub', 'judy@example.com', true, null);
        $found->auth_response = $user->toJson();
        $found->save();
        $this->assertEquals($user, SsoAuth::find('state')?->authenticatedUser());

        // Older than SSO_AUTH_EXPIRATION is gone.
        SsoAuth::query()->where('state', 'state')->update(['created_at' => NaiveDateTime::format(Dates::now()->modify('-11 minutes'))]);
        $this->assertNull(SsoAuth::find('state'));
        SsoAuth::deleteExpired();
        $this->assertSame(0, SsoAuth::query()->count());

        $judy = User::new('judy@example.com');
        $judy->save();
        $this->assertSame($judy->uuid, SsoUser::findByMail('JUDY@example.com')[0]->uuid ?? null);
        $this->assertNull(SsoUser::findByMail('judy@example.com')[1] ?? null);
        SsoUser::new($judy->uuid, 'https://idp/sub')->save();
        $this->assertSame([$judy->uuid, 'https://idp/sub'], [SsoUser::findByIdentifier('https://idp/sub')[0]->uuid ?? null,
            SsoUser::findByMail('judy@example.com')[1]->identifier ?? null]);
    }

    public function test_without_sso(): void
    {
        $this->useDatabase('sqlite');

        $this->get('/identity/sso/prevalidate')->assertStatus(400)->assertJsonPath('message', 'SSO sign-in is not available');
        $this->call('POST', '/identity/connect/token', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            content: 'grant_type=authorization_code&client_id=web&code=x&code_verifier=y')
            ->assertStatus(400)->assertJsonPath('message', 'SSO sign-in is not available');
        // The clients still get the dummy organization of the single SSO integration.
        $this->postJson('/api/organizations/domain/sso/verified')->assertStatus(200)
            ->assertJsonPath('data.0.organizationIdentifier', '00000000-01DC-01DC-01DC-000000000000');
    }
}
