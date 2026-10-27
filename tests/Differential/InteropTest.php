<?php

namespace Tests\Differential;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
}
