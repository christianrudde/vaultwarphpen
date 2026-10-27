<?php

namespace Tests\Feature\Api;

use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * POST /identity/connect/token (src/api/identity.rs).
 */
#[Parity('route:POST /identity/connect/token')]
class LoginTest extends TestCase
{
    use UsesVaultwardenDatabase;

    private const string DEVICE = '0b7f4e5c-1d2a-4b3c-8d9e-0f1a2b3c4d5e';

    private User $user;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');

        $this->user = User::new('judy@example.com', 'Judy');
        $this->user->setPassword('client-hash', 'user-key', resetSecurityStamp: false, allowNextRoute: null);
        $this->user->save();
    }

    public function test_password_login(): void
    {
        $response = $this->login()->assertStatus(200)->assertHeader('Content-Type', 'application/json');

        $this->assertSame([
            'AccountKeys', 'ForcePasswordReset', 'Kdf', 'KdfIterations', 'KdfMemory', 'KdfParallelism', 'Key', 'MasterPasswordPolicy',
            'PrivateKey', 'UserDecryptionOptions', 'access_token', 'expires_in', 'refresh_token', 'scope', 'token_type',
        ], array_keys((array) $response->json()));
        $response->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('scope', 'api offline_access')
            ->assertJsonPath('expires_in', 7200)
            ->assertJsonPath('Key', 'user-key')
            ->assertJsonPath('MasterPasswordPolicy', ['Object' => 'masterPasswordPolicy'])
            ->assertJsonPath('UserDecryptionOptions.MasterPasswordUnlock.Salt', 'judy@example.com');

        $jwt = $this->app->make(Jwt::class);
        $access = $jwt->decode($this->string($response->json('access_token')), JwtIssuer::Login);
        $this->assertSame([$this->user->uuid, self::DEVICE, 'Linux CLI', 'cli', ['api', 'offline_access']],
            [$access['sub'], $access['device'], $access['devicetype'], $access['client_id'], $access['scope']]);

        $refresh = $jwt->decode($this->string($response->json('refresh_token')), JwtIssuer::Login);
        $this->assertSame(['nbf', 'exp', 'iss', 'sub', 'device_token', 'token'], array_keys($refresh));
        $this->assertSame('password', $refresh['sub']);

        $device = Device::findByUuidAndUser(self::DEVICE, $this->user->uuid);
        $this->assertNotNull($device);
        $this->assertSame($device->refresh_token, $refresh['device_token']);
        $this->assertSame(25, $device->atype);
    }

    public function test_refresh_token_login(): void
    {
        $refreshToken = $this->string($this->login()->json('refresh_token'));

        $this->postForm(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => 'cli'])
            ->assertStatus(200)
            ->assertJsonPath('scope', 'api offline_access')
            ->assertJsonPath('token_type', 'Bearer');

        $this->postForm(['grant_type' => 'refresh_token', 'refresh_token' => 'not-a-token'])
            ->assertStatus(400)->assertExactJson(['error' => 'invalid_grant']);
        $this->postForm(['grant_type' => 'refresh_token'])
            ->assertStatus(400)->assertJsonPath('message', 'refresh_token cannot be blank');
    }

    public function test_failed_logins(): void
    {
        $this->login(['password' => 'wrong'])->assertStatus(400)->assertJsonPath('message', 'Username or password is incorrect. Try again');
        $this->login(['username' => 'nobody@example.com'])->assertStatus(400)->assertJsonPath('message', 'Username or password is incorrect. Try again');
        $this->login(['scope' => 'api'])->assertStatus(400)->assertJsonPath('message', 'Scope (api) not supported');
        $this->login(['device_type' => null])->assertStatus(400)->assertJsonPath('message', 'device_type cannot be blank');
        $this->postForm(['grant_type' => 'magic'])->assertStatus(400)->assertJsonPath('message', 'Invalid type');
        $this->postForm(['scope' => 'api'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson('/identity/connect/token', ['grant_type' => 'password'])->assertStatus(415);

        $this->user->enabled = false;
        $this->user->save();
        $this->login()->assertStatus(400)->assertJsonPath('message', 'This user has been disabled');
    }

    public function test_field_names_ignore_case_and_underscores(): void
    {
        $this->postForm([
            'GrantType' => 'password', 'Username' => 'Judy@Example.com', 'Password' => 'client-hash', 'Scope' => 'api offline_access',
            'ClientId' => 'web', 'DeviceIdentifier' => self::DEVICE, 'DeviceName' => 'firefox', 'DeviceType' => 'iOS',
        ])->assertStatus(200);

        $this->assertSame(14, Device::findByUuidAndUser(self::DEVICE, $this->user->uuid)?->atype);
    }

    public function test_server_side_iterations_are_raised_at_login(): void
    {
        $this->user->password_iterations = 100_000;
        $this->user->setPassword('client-hash', null, resetSecurityStamp: false, allowNextRoute: null);
        $this->user->save();
        $this->changeVaultwardenEnvironment(['PASSWORD_ITERATIONS' => '200000']);

        $this->login()->assertStatus(200);

        $user = User::findByUuid($this->user->uuid);
        $this->assertNotNull($user);
        $this->assertSame(200_000, $user->password_iterations);
        $this->assertTrue($user->checkValidPassword('client-hash'));
    }

    public function test_api_key_login(): void
    {
        $this->user->api_key = 'secret-api-key';
        $this->user->save();

        $fields = ['grant_type' => 'client_credentials', 'scope' => 'api', 'client_id' => "user.{$this->user->uuid}",
            'client_secret' => 'secret-api-key', 'device_identifier' => self::DEVICE, 'device_name' => 'cli', 'device_type' => '25'];

        $response = $this->postForm($fields)->assertStatus(200)->assertJsonPath('scope', 'api')->assertJsonMissingPath('refresh_token');
        $this->assertSame('Linux CLI', $this->app->make(Jwt::class)->decode($this->string($response->json('access_token')), JwtIssuer::Login)['devicetype']);

        $this->postForm([...$fields, 'client_secret' => 'wrong'])->assertStatus(400)->assertJsonPath('message', 'Incorrect client_secret');
        $this->postForm([...$fields, 'client_id' => 'judy'])->assertStatus(400)->assertJsonPath('message', 'Malformed client_id');
        $this->postForm([...$fields, 'scope' => 'other'])->assertStatus(400)->assertJsonPath('message', 'Scope not supported');
    }

    public function test_logins_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->login(['password' => 'wrong'])->assertStatus(400);
        }

        $this->login()->assertStatus(429)->assertJsonPath('message', 'Too many login requests');
    }

    private function string(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<string, string|null>  $changes
     * @return TestResponse<Response>
     */
    private function login(array $changes = []): TestResponse
    {
        return $this->postForm(array_filter([
            'grant_type' => 'password',
            'username' => 'judy@example.com',
            'password' => 'client-hash',
            'scope' => 'api offline_access',
            'client_id' => 'cli',
            'device_identifier' => self::DEVICE,
            'device_name' => 'linux',
            'device_type' => '25',
            ...$changes,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function postForm(array $fields, array $headers = []): TestResponse
    {
        return $this->call('POST', '/identity/connect/token', server: [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ...array_combine(array_map(static fn (string $name): string => 'HTTP_'.strtoupper(str_replace('-', '_', $name)), array_keys($headers)), $headers),
        ], content: http_build_query($fields));
    }
}
