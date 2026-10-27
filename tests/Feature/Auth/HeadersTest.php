<?php

namespace Tests\Feature\Auth;

use App\Auth\AuthMethod;
use App\Auth\Headers;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Auth\LoginClaims;
use App\Http\Json;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * The auth::Headers request guard.
 */
class HeadersTest extends TestCase
{
    use UsesVaultwardenDatabase;

    private User $user;

    private Device $device;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000', 'DOMAIN' => 'https://vault.example.com'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');

        $this->user = User::new('erin@example.com', 'Erin');
        $this->user->save();
        $this->device = Device::new('3c5f0a2e-7d4b-4e1f-9a8b-0c1d2e3f4a5b', $this->user->uuid, 'cli', DeviceType::LinuxCLI->value);
        $this->device->save();

        Route::get('/api/test-guard', fn (Headers $headers) => $headers->user->uuid.' '.$headers->device->uuid.' '.$headers->host)
            ->name('core::accounts::post_password');
    }

    public function test_a_valid_access_token(): void
    {
        $this->get('/api/test-guard', ['Authorization' => 'Bearer '.$this->token()])
            ->assertStatus(200)
            ->assertContent("{$this->user->uuid} {$this->device->uuid} https://vault.example.com");
    }

    public function test_the_token_follows_the_last_bearer_prefix(): void
    {
        $this->get('/api/test-guard', ['Authorization' => 'Bearer Bearer '.$this->token()])->assertStatus(200);
    }

    public function test_failures_answer_401_and_log_the_reason(): void
    {
        $log = Log::spy();

        $this->get('/api/test-guard', ['Accept' => 'application/json'])->assertStatus(401)->assertJsonPath('error.code', 401);
        $this->get('/api/test-guard', ['Authorization' => 'Bearer not-a-token'])->assertStatus(401);

        $otherDevice = Device::new('00000000-0000-4000-8000-000000000000', $this->user->uuid, 'gone', 14);
        $this->get('/api/test-guard', ['Authorization' => 'Bearer '.$this->token($otherDevice)])->assertStatus(401);

        $token = $this->token();
        $this->user->security_stamp = 'new-stamp';
        $this->user->save();
        $this->get('/api/test-guard', ['Authorization' => "Bearer {$token}"])->assertStatus(401);

        $log->shouldHaveReceived('error')->with('Unauthorized Error: No access token provided');
        $log->shouldHaveReceived('error')->with('Unauthorized Error: Invalid claim');
        $log->shouldHaveReceived('error')->with('Unauthorized Error: Invalid device id');
        $log->shouldHaveReceived('error')->with('Unauthorized Error: Invalid security stamp');
    }

    public function test_a_stamp_exception_lets_listed_routes_use_the_previous_stamp(): void
    {
        $token = $this->token();
        $this->user->setStampException(['post_password']);
        $this->user->security_stamp = 'rotated-stamp';
        $this->user->save();

        $this->get('/api/test-guard', ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        Route::get('/api/other-route', fn (Headers $headers) => 'ok')->name('core::accounts::get_profile');
        $this->get('/api/other-route', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_an_expired_stamp_exception_is_removed(): void
    {
        $token = $this->token();
        $this->user->stamp_exception = Json::encode(['routes' => ['post_password'], 'security_stamp' => $this->user->security_stamp, 'expire' => time() - 1]);
        $this->user->security_stamp = 'rotated-stamp';
        $this->user->save();

        $this->get('/api/test-guard', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
        $this->assertNull(User::findByUuid($this->user->uuid)?->stamp_exception);
    }

    public function test_tokens_of_other_issuers_are_invalid_claims(): void
    {
        $jwt = $this->app->make(Jwt::class);
        $claims = LoginClaims::default($this->device, $this->user, AuthMethod::Password, 'cli', $jwt)->toArray();
        $token = $jwt->encode([...$claims, 'iss' => $jwt->issuer(JwtIssuer::Admin)]);

        $this->get('/api/test-guard', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    private function token(?Device $device = null): string
    {
        $jwt = $this->app->make(Jwt::class);

        return LoginClaims::default($device ?? $this->device, $this->user, AuthMethod::Password, 'cli', $jwt)->token($jwt);
    }
}
