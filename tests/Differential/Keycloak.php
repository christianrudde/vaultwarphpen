<?php

namespace Tests\Differential;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * The Keycloak of the sixth pair (VW_KEYCLOAK_URL) with the realm of
 * .github/scripts/keycloak-realm.json: a browser that signs in at its login
 * form and keeps its cookies, and the admin API, which ends sessions and
 * changes keys and clients.
 */
final class Keycloak
{
    public const string REALM = 'vaultwarden';

    // Of every user and client of the realm.
    public const string PASSWORD = 'Differential-Password-1';

    public const string SECRET = 'differential+keycloak/secret=';

    // The bootstrap admin that reference.yml gives the container.
    private const string ADMIN = 'admin';

    private const string CALLBACK = 'http://localhost/identity/connect/oidc-signin';

    /**
     * @var array<string, string> Cookies by name; the browser only ever talks to Keycloak
     */
    private array $cookies = [];

    private Client $client;

    public function __construct(public readonly string $url)
    {
        $this->client = new Client(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 30]);
    }

    /**
     * The address of the realm, as SSO_AUTHORITY names it.
     */
    public function authority(string $realm = self::REALM): string
    {
        return "{$this->url}/realms/{$realm}";
    }

    /**
     * Follow the authorization URL like a browser: sign in at the login form
     * when Keycloak shows one, and return the query of its redirect to the
     * callback, with a code or an error.
     */
    public function signIn(string $authorizationUrl, string $username): string
    {
        [$method, $url, $form] = ['GET', $authorizationUrl, null];
        for ($hop = 0; $hop < 10; $hop++) {
            [$status, $headers, $body] = $this->browse($method, $url, $form);
            $location = $headers['location'] ?? null;
            if ($status >= 300 && $status < 400 && $location !== null) {
                if (str_starts_with($location, self::CALLBACK.'?')) {
                    return (string) parse_url($location, PHP_URL_QUERY);
                }
                [$method, $url, $form] = ['GET', str_starts_with($location, '/') ? $this->url.$location : $location, null];

                continue;
            }
            if ($status === 200 && preg_match('/<form\b[^>]*\bid="kc-form-login"[^>]*>/', $body, $tag) === 1
                && preg_match('/\baction="([^"]+)"/', $tag[0], $action) === 1) {
                if ($form !== null) {
                    throw new RuntimeException("Keycloak did not take the password of {$username}.");
                }
                [$method, $url, $form] = ['POST', html_entity_decode($action[1]), ['username' => $username, 'password' => self::PASSWORD, 'credentialId' => '']];

                continue;
            }

            throw new RuntimeException("Keycloak answered {$status} for {$username} at {$url}: ".self::excerpt($body));
        }

        throw new RuntimeException("Keycloak redirected {$username} too often.");
    }

    /**
     * End every session of the user (Admin API: users/{id}/logout).
     */
    public function logOut(string $username): void
    {
        $this->admin('POST', '/users/'.$this->userId($username).'/logout', expected: 204);
    }

    /**
     * Change attributes of a client, such as the algorithm of its ID tokens.
     *
     * @param  array<string, string>  $attributes
     */
    public function setClientAttributes(string $clientId, array $attributes): void
    {
        $clients = json_decode($this->admin('GET', '/clients?'.http_build_query(['clientId' => $clientId])), true);
        $client = is_array($clients) && is_array($clients[0] ?? null) ? $clients[0] : throw new RuntimeException("No client {$clientId} in Keycloak.");
        $client['attributes'] = [...(is_array($client['attributes'] ?? null) ? $client['attributes'] : []), ...$attributes];
        $id = is_string($client['id'] ?? null) ? $client['id'] : '';
        $this->admin('PUT', "/clients/{$id}", $client, 204);
    }

    /**
     * Add a key provider to the realm (a component of type KeyProvider).
     *
     * @param  array<string, string>  $config
     * @return string Its id
     */
    public function addKeyProvider(string $name, string $providerId, array $config): string
    {
        $realm = json_decode($this->admin('GET', ''), true);
        $this->admin('POST', '/components', [
            'name' => $name,
            'providerId' => $providerId,
            'providerType' => 'org.keycloak.keys.KeyProvider',
            'parentId' => is_array($realm) && is_string($realm['id'] ?? null) ? $realm['id'] : self::REALM,
            'config' => array_map(static fn (string $value): array => [$value], $config),
        ], 201, $headers);

        return basename($headers['location'] ?? throw new RuntimeException("Keycloak did not create the key provider {$name}."));
    }

    public function deleteComponent(string $id): void
    {
        $this->admin('DELETE', "/components/{$id}", expected: 204);
    }

    private function userId(string $username): string
    {
        $users = json_decode($this->admin('GET', '/users?'.http_build_query(['username' => $username, 'exact' => 'true'])), true);
        $id = is_array($users) && is_array($users[0] ?? null) ? ($users[0]['id'] ?? null) : null;

        return is_string($id) ? $id : throw new RuntimeException("No user {$username} in Keycloak.");
    }

    /**
     * A request to the admin API of the realm, with a token of the master realm's admin.
     *
     * @param  array<mixed>|null  $json
     * @param  array<string, string>|null  $headers  The response headers
     *
     * @param-out array<string, string> $headers
     */
    private function admin(string $method, string $path, ?array $json = null, int $expected = 200, ?array &$headers = null): string
    {
        $login = $this->client->post("{$this->url}/realms/master/protocol/openid-connect/token", ['form_params' => [
            'grant_type' => 'password', 'client_id' => 'admin-cli', 'username' => self::ADMIN, 'password' => self::ADMIN,
        ]]);
        $token = json_decode((string) $login->getBody(), true);
        $token = is_array($token) && is_string($token['access_token'] ?? null) ? $token['access_token']
            : throw new RuntimeException("No admin token from Keycloak: {$login->getStatusCode()} {$login->getBody()}");

        $response = $this->client->request($method, "{$this->url}/admin/realms/".self::REALM.$path,
            ['headers' => ['Authorization' => "Bearer {$token}"], ...($json === null ? [] : ['json' => $json])]);
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        if ($response->getStatusCode() !== $expected) {
            throw new RuntimeException("Keycloak's admin API answered {$method} {$path} with {$response->getStatusCode()}: {$response->getBody()}");
        }

        return (string) $response->getBody();
    }

    /**
     * One request of the browser, with the cookies Keycloak set so far.
     *
     * @param  array<string, string>|null  $form
     * @return array{int, array<string, string>, string}
     */
    private function browse(string $method, string $url, ?array $form): array
    {
        $cookies = implode('; ', array_map(static fn (string $name, string $value): string => "{$name}={$value}", array_keys($this->cookies), $this->cookies));
        $response = $this->client->request($method, $url, [
            'headers' => $cookies === '' ? [] : ['Cookie' => $cookies],
            ...($form === null ? [] : ['form_params' => $form]),
        ]);

        // Attributes do not matter: Secure, as Keycloak sets it over plain HTTP too, Path or SameSite.
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($value === '' || preg_match('/;\s*(Max-Age=0\b|Expires=\w+, 01[ -]Jan[ -]1970)/i', $cookie) === 1) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return [$response->getStatusCode(), $headers, (string) $response->getBody()];
    }

    private static function excerpt(string $html): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html)));

        return mb_substr(trim((string) $text), 0, 500);
    }
}
