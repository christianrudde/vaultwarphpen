<?php

namespace Tests\Differential;

use App\Http\Controllers\TwoFactor\AuthenticatorController;
use App\Support\Base32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SoftwareAuthenticator;

/**
 * Vaultwarden and this implementation on one database and key file
 * (VW_SHARED_REFERENCE_URL, VW_SHARED_SUBJECT_URL): an account made by one
 * logs in on the other, and the tokens of one work on the other.
 */
class InteropTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('VW_SHARED_REFERENCE_URL') || ! getenv('VW_SHARED_SUBJECT_URL')) {
            $this->markTestSkipped('Set VW_SHARED_REFERENCE_URL and VW_SHARED_SUBJECT_URL to run against a shared database.');
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directions(): iterable
    {
        yield 'registered by Vaultwarden, used here' => ['VW_SHARED_REFERENCE_URL', 'VW_SHARED_SUBJECT_URL'];
        yield 'registered here, used by Vaultwarden' => ['VW_SHARED_SUBJECT_URL', 'VW_SHARED_REFERENCE_URL'];
    }

    #[DataProvider('directions')]
    public function test_accounts_and_tokens_work_on_both(string $registrar, string $user): void
    {
        $registering = new Server((string) getenv($registrar));
        $using = new Server((string) getenv($user));
        $email = 'interop-'.strtolower(substr($registrar, 10, 3)).'@example.com';

        $steps = LoginTest::register($registering, $email);
        $this->assertSame(200, $steps['register']->status, $steps['register']->body);

        $prelogin = $using->json('POST', '/identity/accounts/prelogin', ['email' => $email]);
        $this->assertSame(600000, $prelogin->json('kdfIterations'), $prelogin->body);

        $wrong = $using->form('/identity/connect/token', LoginTest::loginFields($email, 'wrong-hash'));
        $this->assertSame(400, $wrong->status, $wrong->body);

        $login = $using->form('/identity/connect/token', LoginTest::loginFields($email));
        $this->assertSame(200, $login->status, $login->body);
        $this->assertSame('user-key', $login->json('Key'));

        // The refresh token of one server renews the session on the other.
        $refresh = $registering->form('/identity/connect/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $login->jsonString('refresh_token'), 'client_id' => 'cli',
        ]);
        $this->assertSame(200, $refresh->status, $refresh->body);
    }

    /**
     * Two-factor methods set up on one server work on the other: an
     * authenticator key and a security key, whose counter the other updates.
     */
    #[DataProvider('directions')]
    public function test_two_factor_methods_work_on_both(string $registrar, string $user): void
    {
        $registering = new Server((string) getenv($registrar));
        $using = new Server((string) getenv($user));
        $email = 'interop-2fa-'.strtolower(substr($registrar, 10, 3)).'@example.com';
        $auth = CipherTest::session($registering, $email)[0];
        $login = LoginTest::loginFields($email);
        $password = ['masterPasswordHash' => 'client-hash'];

        $setup = $registering->json('POST', '/api/two-factor/get-authenticator', $password, $auth);
        $secret = Base32::decode($setup->jsonString('authenticator.key')) ?? '';
        $activated = $registering->json('POST', '/api/two-factor/authenticator', ['key' => $setup->jsonString('authenticator.key'),
            'userVerificationToken' => $setup->jsonString('userVerificationToken'), 'token' => AuthenticatorController::totp($secret, time())], $auth);
        $this->assertSame(200, $activated->status, $activated->body);

        $key = new SoftwareAuthenticator;
        $token = $registering->json('POST', '/api/two-factor/get-webauthn', $password, $auth)->jsonString('userVerificationToken');
        $challenge = $registering->json('POST', '/api/two-factor/get-webauthn-challenge', ['userVerificationToken' => $token], $auth);
        $registered = $registering->json('POST', '/api/two-factor/webauthn', ['id' => 1, 'name' => 'Key', 'userVerificationToken' => $token,
            'deviceResponse' => $key->register(TwoFactorTest::options($challenge))], $auth);
        $this->assertSame(200, $registered->status, $registered->body);

        $totp = $using->form('/identity/connect/token', [...$login, 'two_factor_provider' => '0', 'two_factor_token' => AuthenticatorController::totp($secret, time() + 30)]);
        $this->assertSame(200, $totp->status, $totp->body);

        foreach ([$using, $registering, $using] as $server) {
            $prompt = $server->form('/identity/connect/token', $login);
            $this->assertEqualsCanonicalizing(['0', '7'], $prompt->json('TwoFactorProviders'), $prompt->body);
            $answer = $server->form('/identity/connect/token', [...$login, 'two_factor_provider' => '7', 'two_factor_token' => $key->assert(TwoFactorTest::request($prompt))]);
            $this->assertSame(200, $answer->status, $answer->body);
        }
    }
}
