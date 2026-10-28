<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Device;
use App\Models\TwoFactorDuoContext;
use App\Models\User;
use App\Support\Random;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * YubiKey and Duo logins against the fake services of the differential tests
 * (.github/scripts/fake-services.php), which this test starts over HTTPS with
 * a certificate authority of its own.
 */
class TwoFactorServicesTest extends TestCase
{
    use UsesVaultwardenDatabase;

    private const string DEVICE = '0b7f4e5c-1d2a-4b3c-8d9e-0f1a2b3c4d5e';

    private const string YUBICO_KEY = 'MDEyMzQ1Njc4OWFiY2RlZmdoaWo=';

    private const string DUO_CLIENT = 'DIFEATURETESTGLOBAL0';

    /**
     * @var resource|null
     */
    private static mixed $process = null;

    private static string $folder = '';

    private static string $host = '';

    /**
     * @var array<string, string>
     */
    private array $session;

    public static function setUpBeforeClass(): void
    {
        self::$folder = sys_get_temp_dir().'/vw-services-'.bin2hex(random_bytes(6));
        mkdir(self::$folder);
        $folder = escapeshellarg(self::$folder);
        exec("cd {$folder} && openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Test CA' -addext 'basicConstraints=critical,CA:TRUE'"
            ." -addext 'keyUsage=critical,keyCertSign' -keyout ca.key -out ca.pem 2>&1"
            ." && openssl req -newkey rsa:2048 -nodes -subj '/CN=127.0.0.1' -keyout services.key -out services.csr 2>&1"
            ." && printf 'subjectAltName=IP:127.0.0.1\\nbasicConstraints=critical,CA:FALSE\\nextendedKeyUsage=serverAuth\\n' > services.ext"
            .' && openssl x509 -req -in services.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 1 -extfile services.ext -out services.pem 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Cannot create the certificates: '.implode("\n", $output));
        }

        // A free port, taken right after it is found.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = $probe === false ? '' : (string) stream_socket_get_name($probe, false);
        if ($probe !== false) {
            fclose($probe);
        }
        $port = substr($address, strrpos($address, ':') + 1);
        self::$host = "127.0.0.1:{$port}";

        $script = dirname(__DIR__, 3).'/.github/scripts/fake-services.php';
        $process = proc_open([PHP_BINARY, $script, $port, self::$folder.'/services.pem', self::$folder.'/services.key', self::$folder.'/state'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', self::$folder.'/services.log', 'w']], $pipes, null, ['YUBICO_SECRET_KEY' => self::YUBICO_KEY]);
        self::$process = $process === false ? null : $process;

        $context = stream_context_create(['ssl' => ['cafile' => self::$folder.'/ca.pem']]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@stream_socket_client('tls://'.self::$host, $errno, $error, 1, STREAM_CLIENT_CONNECT, $context) !== false) {
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('The fake services did not start: '.file_get_contents(self::$folder.'/services.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        exec('rm -rf '.escapeshellarg(self::$folder));
    }

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000', 'SSL_CERT_FILE' => self::$folder.'/ca.pem', 'HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS' => 'false',
            'YUBICO_CLIENT_ID' => '1', 'YUBICO_SECRET_KEY' => self::YUBICO_KEY, 'YUBICO_SERVER' => 'https://'.self::$host.'/wsapi/2.0/verify',
            'DUO_HOST' => self::$host, 'DUO_IKEY' => self::DUO_CLIENT, 'DUO_SKEY' => substr(hash('sha256', 'duo:'.self::DUO_CLIENT), 0, 40)];
    }

    #[DataProvider('drivers')]
    public function test_yubikey(string $driver): void
    {
        $this->signUp($driver);
        $otp = static fn (string $ending = 'dddd'): string => 'ccccccbcgujh'.str_repeat('t', 28).$ending;

        $setup = $this->postJson('/api/two-factor/get-yubikey', ['masterPasswordHash' => 'client-hash'], $this->session)
            ->assertStatus(200)->assertJsonPath('yubiKey', ['enabled' => false]);
        $activate = ['nfc' => false, 'userVerificationToken' => $setup->json('userVerificationToken')];
        $this->postJson('/api/two-factor/yubikey', [...$activate, 'key1' => $otp('vvvv')], $this->session)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid Yubikey OTP provided');
        $this->postJson('/api/two-factor/yubikey', [...$activate, 'key1' => $otp(), 'key2' => 'ccccccdddddd'], $this->session)
            ->assertStatus(200)->assertExactJson(['yubiKey' => ['Key1' => 'ccccccbcgujh', 'Key2' => 'ccccccdddddd', 'enabled' => true, 'nfc' => false]]);

        $this->login()->assertStatus(400)->assertJsonPath('TwoFactorProviders2', ['3' => ['Nfc' => false]]);
        $this->login(['two_factor_provider' => '3', 'two_factor_token' => $otp('ssss')])->assertStatus(400)
            ->assertJsonPath('message', 'Failed to verify Yubikey against OTP server');
        $this->login(['two_factor_provider' => '3', 'two_factor_token' => $otp()])->assertStatus(200);
    }

    #[DataProvider('drivers')]
    public function test_duo(string $driver): void
    {
        $this->signUp($driver);
        $default = '<To use the global Duo keys, please leave these fields untouched>';
        $setup = $this->postJson('/api/two-factor/get-duo', ['masterPasswordHash' => 'client-hash'], $this->session)
            ->assertStatus(200)->assertJsonPath('duo.host', $default);
        $this->postJson('/api/two-factor/duo', ['host' => $default, 'clientId' => $default, 'clientSecret' => $default,
            'userVerificationToken' => $setup->json('userVerificationToken')], $this->session)->assertStatus(200)->assertJsonPath('duo.host', '<global_secret>');

        $url = $this->login()->assertStatus(400)->json('TwoFactorProviders2.2.AuthUrl');
        $this->assertIsString($url);
        $this->assertStringStartsWith('https://'.self::$host.'/oauth/v1/authorize?response_type=code&client_id='.self::DUO_CLIENT.'&request=', $url);
        $this->assertSame(1, TwoFactorDuoContext::query()->count());

        // The fake Duo sends the client back to the web vault's bridge at once.
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true], 'ssl' => ['cafile' => self::$folder.'/ca.pem']]);
        file_get_contents($url, false, $context);
        $location = '';
        foreach (http_get_last_response_headers() ?? [] as $header) {
            $location = str_starts_with($header, 'Location: ') ? substr($header, 10) : $location;
        }
        $this->assertStringStartsWith('http://localhost/duo-redirect-connector.html?client=cli&state=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $token = "{$this->string($query['duo_code'] ?? null)}|{$this->string($query['state'] ?? null)}";

        $this->login(['two_factor_provider' => '2', 'two_factor_token' => $token])->assertStatus(200);
        $this->assertSame(0, TwoFactorDuoContext::query()->count());
        $this->login(['two_factor_provider' => '2', 'two_factor_token' => $token])->assertStatus(400)
            ->assertJsonPath('message', 'Error validating duo authentication');

        // Expired contexts are purged.
        TwoFactorDuoContext::store('expired', 'judy@example.com', 'nonce', -1);
        TwoFactorDuoContext::purgeExpired();
        $this->assertNull(TwoFactorDuoContext::findByState('expired'));
    }

    private function signUp(string $driver): void
    {
        $this->useDatabase($driver);
        $user = User::new('judy@example.com', 'Judy');
        $user->setPassword('client-hash', 'user-key', resetSecurityStamp: false, allowNextRoute: null);
        $user->save();
        $device = Device::new(Random::uuid(), $user->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);
        $this->session = ['Authorization' => 'Bearer '.LoginClaims::default($device, $user, AuthMethod::Password, 'cli', $jwt)->token($jwt)];
    }

    private function string(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<string, string>  $changes
     * @return TestResponse<Response>
     */
    private function login(array $changes = []): TestResponse
    {
        return $this->call('POST', '/identity/connect/token', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: http_build_query([
            'grant_type' => 'password', 'username' => 'judy@example.com', 'password' => 'client-hash', 'scope' => 'api offline_access',
            'client_id' => 'cli', 'device_identifier' => self::DEVICE, 'device_name' => 'linux', 'device_type' => '25', ...$changes,
        ]));
    }
}
