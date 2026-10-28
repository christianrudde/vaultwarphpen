<?php

namespace Tests\Differential;

use RuntimeException;
use Tests\Attributes\Parity;

/**
 * SSO with a real OpenID provider: the pair of servers that log in at
 * Keycloak (VW_KEYCLOAK_*, .github/scripts/keycloak-realm.json). The test
 * plays the browser at Keycloak's login form and changes the SSO settings
 * of both servers through their admin panels between the steps; Keycloak's
 * admin API ends sessions and changes keys and clients.
 */
class KeycloakTest extends DifferentialTestCase
{
    private const string VERIFIER = 'differential-keycloak-verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

    private const string DEVICE = '6e7f8091-a2b3-4c4d-9e5f-6a7b8c9d0e1f';

    private const string ADMIN_TOKEN = 'differential-admin-token';

    private const string FAKE_ORGANIZATION = '00000000-01DC-01DC-01DC-000000000000';

    public function test_sign_up_refresh_and_session_end(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = [];
            $browser = $this->keycloak();
            $code = $this->code($server, $steps, 'sign up', 'kc-alice', $browser);
            $steps['sign up login'] = $login = self::ok(self::login($server, $code));
            // Keycloak's access tokens are JWTs: the session follows their lifetime and that of its refresh tokens.
            $steps['sign up lifetimes'] = self::lifetimes($login);
            $steps['profile'] = $server->send('GET', '/api/accounts/profile', self::auth($login));

            // Keycloak remembers the browser and sends it back without the login form.
            $code = $this->code($server, $steps, 'still signed in', 'kc-alice', $browser);
            $steps['still signed in login'] = $login = self::ok(self::login($server, $code));

            // The realm rotates refresh tokens and refuses used ones; the servers reuse their answer for 30 seconds.
            $steps['refresh'] = $refreshed = self::ok(self::refresh($server, $login->jsonString('refresh_token')));
            $steps['refresh lifetimes'] = self::lifetimes($refreshed);
            $steps['refresh with the used token'] = self::ok(self::refresh($server, $login->jsonString('refresh_token')));
            $steps['refresh with the new token'] = $again = self::ok(self::refresh($server, $refreshed->jsonString('refresh_token')));

            $this->keycloak()->logOut('kc-alice');
            $steps['refresh after the session ended at Keycloak'] = self::refused(self::refresh($server, $again->jsonString('refresh_token')), 'invalid_grant');
            $code = $this->code($server, $steps, 'sign in again', 'kc-alice', $browser);
            $steps['sign in again login'] = self::ok(self::login($server, $code));

            return $steps;
        });
    }

    public function test_linking_by_email_and_its_verification(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = self::register($server, 'kc-linked@example.com');
            $code = $this->code($server, $steps, 'linked', 'kc-linked');
            $steps['linked login'] = self::ok(self::login($server, $code));

            // Keycloak sends email_verified: false.
            $code = $this->code($server, $steps, 'unverified', 'kc-unverified');
            $steps['unverified login'] = self::refused(self::login($server, $code), 'You need to verify your email');
            $steps += self::register($server, 'kc-unverified-linked@example.com');
            $code = $this->code($server, $steps, 'unverified linked', 'kc-unverified-linked');
            $steps['unverified linked login'] = self::refused(self::login($server, $code), 'Email is not verified');

            // Two Keycloak users with one address: the second is another identity.
            $code = $this->code($server, $steps, 'first twin', 'kc-twin-1');
            $steps['first twin login'] = self::ok(self::login($server, $code));
            $code = $this->code($server, $steps, 'second twin', 'kc-twin-2');
            $steps['second twin login'] = self::refused(self::login($server, $code), 'Existing SSO user with same email');

            return $steps;
        });
    }

    #[Parity('config:SSO_SIGNUPS_ALLOWED')]
    #[Parity('config:SSO_SIGNUPS_MATCH_EMAIL')]
    public function test_sign_ups_and_matching_by_email(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = [];
            try {
                $steps['no sign-ups'] = self::configure($server, ['sso_signups_allowed' => false]);
                $code = $this->code($server, $steps, 'without sign-ups', 'kc-invitee');
                $steps['without sign-ups login'] = self::refused(self::login($server, $code), 'Signups are disabled');
                $steps['sign-ups for example.org'] = self::configure($server, ['sso_signups_allowed' => false, 'signups_domains_whitelist' => 'example.org']);
                $code = $this->code($server, $steps, 'from another domain', 'kc-invitee');
                $steps['from another domain login'] = self::refused(self::login($server, $code), 'Email domain not allowed');

                $steps['no sign-ups again'] = self::configure($server, ['sso_signups_allowed' => false]);
                $steps['invite'] = $server->json('POST', '/admin/invite', ['email' => 'kc-invitee@example.com'], self::adminSession($server));
                $code = $this->code($server, $steps, 'invited', 'kc-invitee');
                $steps['invited login'] = self::ok(self::login($server, $code));

                $steps['no matching by email'] = self::configure($server, ['sso_signups_match_email' => false]);
                $steps += self::register($server, 'kc-no-match@example.com');
                $code = $this->code($server, $steps, 'same email', 'kc-no-match');
                $steps['same email login'] = self::refused(self::login($server, $code), 'Existing non SSO user with same email');
            } finally {
                $steps['reset'] = self::configure($server);
            }

            return $steps;
        });
    }

    /**
     * A client like Microsoft Entra ID: the ID token has the email address, but no email_verified.
     */
    #[Parity('config:SSO_ALLOW_UNKNOWN_EMAIL_VERIFICATION')]
    public function test_unknown_email_verification(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $client = ['sso_client_id' => 'vaultwarden-unverified', 'sso_client_secret' => Keycloak::SECRET, 'sso_scopes' => 'profile'];
            $steps = self::register($server, 'kc-unknown-linked@example.com');
            try {
                $steps['without email_verified'] = self::configure($server, $client);
                $code = $this->code($server, $steps, 'unknown', 'kc-unknown');
                $steps['unknown login'] = self::refused(self::login($server, $code), 'does not send email verification status');
                $code = $this->code($server, $steps, 'unknown linked', 'kc-unknown-linked');
                $steps['unknown linked login'] = self::refused(self::login($server, $code), 'Email verification status is unknown');

                $steps['unknown allowed'] = self::configure($server, [...$client, 'sso_allow_unknown_email_verification' => true]);
                $code = $this->code($server, $steps, 'allowed', 'kc-unknown');
                $steps['allowed login'] = self::ok(self::login($server, $code));
                $code = $this->code($server, $steps, 'allowed linked', 'kc-unknown-linked');
                $steps['allowed linked login'] = self::ok(self::login($server, $code));
            } finally {
                $steps['reset'] = self::configure($server);
            }

            return $steps;
        });
    }

    #[Parity('config:SSO_ONLY')]
    #[Parity('config:SSO_MASTER_PASSWORD_POLICY')]
    public function test_sso_only_and_the_master_password_policy(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = self::register($server, 'kc-password@example.com');
            $steps['password login'] = $password = $server->form('/identity/connect/token', LoginTest::loginFields('kc-password@example.com'));
            try {
                $steps['sso only'] = self::configure($server, ['sso_only' => true,
                    'sso_master_password_policy' => '{"minLength":14,"enforceOnLogin":true,"requireUpper":true,"minComplexity":4}']);
                $steps['password login refused'] = self::refused($server->form('/identity/connect/token', LoginTest::loginFields('kc-password@example.com')), 'SSO sign-in is required');
                $steps['refresh of the password login'] = self::refused(self::refresh($server, $password->jsonString('refresh_token'), 'cli'), 'invalid_grant');
                $steps['config'] = self::withoutServer($server->send('GET', '/api/config'));
                $steps['css'] = $server->send('GET', '/css/vaultwarden.css');
                $code = $this->code($server, $steps, 'only sso', 'kc-sso-only');
                $steps['only sso login'] = $login = self::ok(self::login($server, $code));
                $steps['policy'] = $server->send('GET', '/api/organizations/'.self::FAKE_ORGANIZATION.'/policies/master-password', self::auth($login));
            } finally {
                $steps['reset'] = self::configure($server);
            }
            $steps['password login allowed again'] = $server->form('/identity/connect/token', LoginTest::loginFields('kc-password@example.com'));
            $steps['config again'] = self::withoutServer($server->send('GET', '/api/config'));

            return $steps;
        });
    }

    #[Parity('config:SSO_AUTH_ONLY_NOT_SESSION')]
    public function test_sessions_of_their_own(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = [];
            try {
                $steps['sessions of their own'] = self::configure($server, ['sso_auth_only_not_session' => true]);
                $code = $this->code($server, $steps, 'own session', 'kc-own-session');
                $steps['own session login'] = $login = self::ok(self::login($server, $code));
                $steps['own session lifetimes'] = self::lifetimes($login);

                $this->keycloak()->logOut('kc-own-session');
                $steps['refresh after the session ended at Keycloak'] = $refreshed = self::ok(self::refresh($server, $login->jsonString('refresh_token')));
                $steps['refresh lifetimes'] = self::lifetimes($refreshed);
            } finally {
                $steps['reset'] = self::configure($server);
            }
            // The refresh token holds no token of Keycloak's.
            $steps['refresh in the session of Keycloak'] = self::refused(self::refresh($server, $refreshed->jsonString('refresh_token')), 'invalid_grant');

            return $steps;
        });
    }

    /**
     * offline_access makes Keycloak issue offline tokens, which outlive its session.
     */
    public function test_offline_access(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = [];
            try {
                $steps['offline access'] = self::configure($server, ['sso_scopes' => 'email profile offline_access']);
                $code = $this->code($server, $steps, 'offline', 'kc-offline');
                $steps['offline login'] = $login = self::ok(self::login($server, $code));
                $steps['offline lifetimes'] = self::lifetimes($login);
                $steps['refresh'] = $refreshed = self::ok(self::refresh($server, $login->jsonString('refresh_token')));
                $steps['refresh lifetimes'] = self::lifetimes($refreshed);
            } finally {
                $steps['reset'] = self::configure($server);
            }

            return $steps;
        });
    }

    /**
     * Without a refresh token, the refresh token keeps Keycloak's access token
     * and refreshes while the user info still answers to it.
     */
    public function test_access_tokens_without_refresh_tokens(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = [];
            try {
                $steps['no refresh tokens'] = self::configure($server, ['sso_client_id' => 'vaultwarden-access-only', 'sso_client_secret' => Keycloak::SECRET]);
                $code = $this->code($server, $steps, 'access only', 'kc-access-only');
                $steps['access only login'] = $login = self::ok(self::login($server, $code));
                $steps['access only lifetimes'] = self::lifetimes($login);
                $steps['refresh'] = $refreshed = self::ok(self::refresh($server, $login->jsonString('refresh_token')));
                $steps['refresh lifetimes'] = self::lifetimes($refreshed);

                $this->keycloak()->logOut('kc-access-only');
                $steps['refresh after the session ended at Keycloak'] = self::refused(self::refresh($server, $refreshed->jsonString('refresh_token')), 'invalid_grant');
            } finally {
                $steps['reset'] = self::configure($server);
            }

            return $steps;
        });
    }

    #[Parity('config:SSO_AUDIENCE_TRUSTED')]
    public function test_trusted_audiences(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $client = ['sso_client_id' => 'vaultwarden-audiences', 'sso_client_secret' => Keycloak::SECRET];
            $steps = [];
            try {
                $steps['second audience'] = self::configure($server, $client);
                $code = $this->code($server, $steps, 'untrusted', 'kc-audience');
                $steps['untrusted login'] = self::refused(self::login($server, $code), 'is not a trusted audience');
                $steps['trusted audience'] = self::configure($server, [...$client, 'sso_audience_trusted' => '^vaultwarden-other-.*$']);
                $code = $this->code($server, $steps, 'trusted', 'kc-audience');
                $steps['trusted login'] = self::ok(self::login($server, $code));
                $steps['another trusted audience'] = self::configure($server, [...$client, 'sso_audience_trusted' => '^other-']);
                $code = $this->code($server, $steps, 'still untrusted', 'kc-audience');
                $steps['still untrusted login'] = self::refused(self::login($server, $code), 'is not a trusted audience');
            } finally {
                $steps['reset'] = self::configure($server);
            }

            return $steps;
        });
    }

    /**
     * A new signing key at Keycloak: a cached provider has the old key set
     * until an ID token fails to verify, which drops it from the cache.
     */
    #[Parity('config:SSO_CLIENT_CACHE_EXPIRATION')]
    public function test_key_rotation_with_the_client_cache(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $steps = [];
            try {
                $steps['cache'] = self::configure($server, ['sso_client_cache_expiration' => 3600]);
                $code = $this->code($server, $steps, 'before the rotation', 'kc-rotation');
                $steps['before the rotation login'] = self::ok(self::login($server, $code));

                $key = $this->keycloak()->addKeyProvider('rotated', 'rsa-generated', ['priority' => '1000', 'algorithm' => 'RS256', 'keySize' => '2048']);
                try {
                    $code = $this->code($server, $steps, 'with the cached keys', 'kc-rotation');
                    $steps['with the cached keys login'] = self::refused(self::login($server, $code), 'Signature verification failed');
                    $code = $this->code($server, $steps, 'with the new keys', 'kc-rotation');
                    $steps['with the new keys login'] = self::ok(self::login($server, $code));
                } finally {
                    $this->keycloak()->deleteComponent($key);
                }
            } finally {
                $steps['reset'] = self::configure($server);
            }

            return $steps;
        });
    }

    /**
     * ID tokens signed with RSA-PSS, ECDSA and EdDSA; openidconnect cannot verify P-521.
     */
    public function test_signing_algorithms(): void
    {
        $keycloak = $this->keycloak();
        $providers = [];
        try {
            $providers[] = $keycloak->addKeyProvider('ps256', 'rsa-generated', ['priority' => '100', 'algorithm' => 'PS256', 'keySize' => '2048']);
            $providers[] = $keycloak->addKeyProvider('es256', 'ecdsa-generated', ['priority' => '100', 'ecdsaEllipticCurveKey' => 'P-256']);
            $providers[] = $keycloak->addKeyProvider('es512', 'ecdsa-generated', ['priority' => '100', 'ecdsaEllipticCurveKey' => 'P-521']);
            $providers[] = $keycloak->addKeyProvider('eddsa', 'eddsa-generated', ['priority' => '100', 'eddsaEllipticCurveKey' => 'Ed25519']);

            $this->assertSameKeycloakFlows(function (Server $server) use ($keycloak): array {
                $steps = [];
                try {
                    $steps['algorithms'] = self::configure($server, ['sso_client_id' => 'vaultwarden-algorithms', 'sso_client_secret' => Keycloak::SECRET]);
                    foreach (['PS256', 'ES256', 'ES512', 'EdDSA'] as $algorithm) {
                        $keycloak->setClientAttributes('vaultwarden-algorithms', ['id.token.signed.response.alg' => $algorithm]);
                        $code = $this->code($server, $steps, $algorithm, 'kc-algorithms');
                        $login = self::login($server, $code);
                        $steps["{$algorithm} login"] = $algorithm === 'ES512' ? self::refused($login, 'Signature verification failed') : self::ok($login);
                    }
                } finally {
                    $keycloak->setClientAttributes('vaultwarden-algorithms', ['id.token.signed.response.alg' => 'RS256']);
                    $steps['reset'] = self::configure($server);
                }

                return $steps;
            });
        } finally {
            array_map($keycloak->deleteComponent(...), $providers);
        }
    }

    #[Parity('config:SSO_AUTHORIZE_EXTRA_PARAMS')]
    public function test_errors_of_the_provider(): void
    {
        $this->assertSameKeycloakFlows(function (Server $server): array {
            $keycloak = $this->keycloak();
            $steps = [];
            try {
                $steps['wrong secret'] = self::configure($server, ['sso_client_secret' => 'wrong-secret']);
                $code = $this->code($server, $steps, 'with the wrong secret', 'kc-errors');
                $steps['with the wrong secret login'] = self::refused(self::login($server, $code), 'Failed to contact token endpoint');

                // Keycloak answers at once, with login_required.
                $steps['no prompt'] = self::configure($server, ['sso_authorize_extra_params' => 'prompt=none&ui_locales=de']);
                $code = $this->code($server, $steps, 'not signed in', 'kc-errors');
                $steps['not signed in login'] = self::refused(self::login($server, $code), 'SSO authorization failed: login_required');

                $steps['unknown scope'] = self::configure($server, ['sso_scopes' => 'email profile unknown-scope']);
                $code = $this->code($server, $steps, 'with an unknown scope', 'kc-errors');
                $steps['with an unknown scope login'] = self::login($server, $code);

                // The issuer has no trailing slash.
                $steps['authority with a slash'] = self::configure($server, ['sso_authority' => $keycloak->authority().'/']);
                $steps['authority with a slash authorize'] = self::blanked(self::authorize($server, 'slash-state'));
                $steps['unknown realm'] = self::configure($server, ['sso_authority' => $keycloak->authority('nothing')]);
                $steps['unknown realm authorize'] = self::blanked(self::authorize($server, 'realm-state'));
            } finally {
                $steps['reset'] = self::configure($server);
            }

            return $steps;
        });
    }

    /**
     * @param  callable(Server): array<string, Exchange>  $flow
     */
    private function assertSameKeycloakFlows(callable $flow): void
    {
        $this->keycloak();
        $this->assertSameFlowsOn((string) getenv('VW_KEYCLOAK_REFERENCE_URL'), (string) getenv('VW_KEYCLOAK_SUBJECT_URL'), $flow);
    }

    /**
     * A browser of its own, without cookies.
     */
    private function keycloak(): Keycloak
    {
        if (! getenv('VW_KEYCLOAK_URL')) {
            $this->markTestSkipped('Set KEYCLOAK_URL for differential.sh to compare SSO at Keycloak.');
        }

        return new Keycloak((string) getenv('VW_KEYCLOAK_URL'));
    }

    /**
     * Authorize, sign in at Keycloak as the user and come back: the code the
     * client then has. The answers of the server go into the steps, with the
     * random code and nonce blanked out.
     *
     * @param  array<string, Exchange>  $steps
     */
    private function code(Server $server, array &$steps, string $name, string $username, ?Keycloak $browser = null): string
    {
        $authorize = self::authorize($server, str_replace(' ', '-', $name).'-state');
        $steps["{$name} authorize"] = self::blanked($authorize);
        $location = $authorize->header('location') ?? throw new RuntimeException("No redirect to Keycloak: {$authorize->status} {$authorize->body}");
        preg_match('/VW_SSO_BINDING=([^;]+)/', (string) $authorize->header('set-cookie'), $binding);

        $query = ($browser ?? $this->keycloak())->signIn($location, $username);
        $signin = $server->send('GET', '/identity/connect/oidc-signin?'.$query, ['Cookie' => 'VW_SSO_BINDING='.($binding[1] ?? '')]);
        $steps["{$name} back from Keycloak"] = self::blanked($signin);
        parse_str((string) parse_url((string) $signin->header('location'), PHP_URL_QUERY), $params);

        return is_string($params['code'] ?? null) ? $params['code'] : throw new RuntimeException("No code for the client: {$signin->status} {$signin->body}");
    }

    private static function authorize(Server $server, string $state): Exchange
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');

        return $server->send('GET', '/identity/connect/authorize?'.http_build_query(['client_id' => 'web',
            'redirect_uri' => 'https://example.com/redirect', 'response_type' => 'code', 'scope' => 'api offline_access', 'state' => $state,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256']));
    }

    private static function login(Server $server, string $code): Exchange
    {
        return $server->form('/identity/connect/token', ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => 'web', 'scope' => 'api offline_access', 'device_identifier' => self::DEVICE, 'device_name' => 'firefox', 'device_type' => '10']);
    }

    /**
     * A login or refresh that must succeed: a failure on both servers is a broken setup, not a match.
     */
    private static function ok(Exchange $exchange): Exchange
    {
        $exchange->jsonString('access_token');

        return $exchange;
    }

    /**
     * A login or refresh that must be refused with the message: one that passes
     * on both servers means the step before it did not take effect.
     */
    private static function refused(Exchange $exchange, string $message): Exchange
    {
        if ($exchange->status !== 400 || ! str_contains($exchange->body, $message)) {
            throw new RuntimeException("Expected a refusal with {$message}: {$exchange->status} {$exchange->body}");
        }

        return $exchange;
    }

    private static function refresh(Server $server, string $refreshToken, string $clientId = 'web'): Exchange
    {
        return $server->form('/identity/connect/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refreshToken]);
    }

    /**
     * @return array<string, string>
     */
    private static function auth(Exchange $login): array
    {
        return ['Authorization' => 'Bearer '.$login->jsonString('access_token'), 'Accept' => 'application/json'];
    }

    /**
     * The steps of a registration with a master password.
     *
     * @return array<string, Exchange>
     */
    private static function register(Server $server, string $email): array
    {
        $steps = [];
        foreach (LoginTest::register($server, $email) as $step => $exchange) {
            $steps["{$step} of {$email}"] = $exchange;
        }

        return $steps;
    }

    /**
     * How long the tokens of a login or refresh are valid, in minutes, and
     * which token of Keycloak's the refresh token keeps: the tokens themselves
     * differ. A refresh may keep the end of the access token, a second or so
     * closer then.
     */
    private static function lifetimes(Exchange $login): Exchange
    {
        $claims = static function (string $token): array {
            $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true);

            return is_array($claims) ? $claims : [];
        };
        $access = $claims($login->jsonString('access_token'));
        $refresh = $claims($login->jsonString('refresh_token'));
        $minutes = static fn (array $claims): ?int => is_int($claims['exp'] ?? null) && is_int($claims['nbf'] ?? null)
            ? intdiv($claims['exp'] - $claims['nbf'] + 30, 60) : null;

        return new Exchange(200, ['content-type' => 'application/json'], (string) json_encode([
            'access token' => $minutes($access),
            'refresh token' => $minutes($refresh),
            'kept' => is_array($refresh['token'] ?? null) ? array_keys($refresh['token']) : ($refresh['token'] ?? null),
        ]));
    }

    /**
     * /api/config without the server's name, which differs by design (AGENTS.md).
     */
    private static function withoutServer(Exchange $config): Exchange
    {
        $body = json_decode($config->body, true);
        if (! is_array($body)) {
            return $config;
        }
        unset($body['server'], $body['gitHash']);

        return $config->withBody((string) json_encode($body));
    }

    /**
     * Save SSO settings in config.json through the admin panel; without any, delete it.
     *
     * @param  array<string, mixed>  $settings
     */
    private static function configure(Server $server, array $settings = []): Exchange
    {
        $session = self::adminSession($server);

        return $settings === []
            ? $server->send('POST', '/admin/config/delete', ['Content-Type' => 'application/json', ...$session])
            : $server->json('POST', '/admin/config', $settings, $session);
    }

    /**
     * @return array<string, string> The Cookie header of an admin session
     */
    private static function adminSession(Server $server): array
    {
        $login = $server->form('/admin', ['token' => self::ADMIN_TOKEN]);
        if (preg_match('/VW_ADMIN=([^;]+)/', (string) $login->header('Set-Cookie'), $match) !== 1) {
            throw new RuntimeException("No admin session in the {$login->status} response: {$login->body}");
        }

        return ['Cookie' => "VW_ADMIN={$match[1]}"];
    }

    /**
     * The redirect without the random nonce and Keycloak's code, which the
     * server passes on to the client (or, after an error, the state).
     */
    private static function blanked(Exchange $exchange): Exchange
    {
        $location = $exchange->header('location');
        if ($location === null) {
            return $exchange;
        }
        $location = (string) preg_replace(['/([?&]code=)[^&#]+/', '/([?&]nonce=)[A-Za-z0-9_-]{22}/'], ['$1<code>', '$1<nonce>'], $location);

        return new Exchange($exchange->status, ['location' => $location] + $exchange->headers, $exchange->body);
    }
}
