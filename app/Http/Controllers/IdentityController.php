<?php

namespace App\Http\Controllers;

use App\Auth\AuthMethod;
use App\Auth\AuthTokens;
use App\Auth\ClientIp;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Auth\LoginClaims;
use App\Auth\MasterPasswordPolicy;
use App\Config\Config;
use App\Http\Accept;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\RegisterData;
use App\Http\EmptyResponse;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Mail\Mail;
use App\Models\Device;
use App\Models\Invitation;
use App\Models\OrganizationApiKey;
use App\Models\OrgPolicy;
use App\Models\Send;
use App\Models\TwoFactor;
use App\Models\User;
use App\Support\Dates;
use App\Support\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * src/api/identity.rs
 */
final readonly class IdentityController
{
    public function __construct(
        private Config $config,
        private Jwt $jwt,
        private RateLimiter $limiter,
        private AccountsController $accounts,
    ) {}

    /**
     * POST /identity/connect/token: password, refresh token and API key logins.
     */
    public function login(Request $request): Response
    {
        $data = Body::form($request);
        // ConnectData: names without case, each with an alias without underscores.
        $field = static fn (string $name): ?string => Body::optionalFormField($data, array_values(array_unique([$name, str_replace('_', '', $name)])));
        // The only field that is not an Option: missing or repeated, the form is rejected.
        $grantType = $field('grant_type') ?? throw new HttpStatus(422, 'Field `grant_type` is missing or repeated.');

        $ip = ClientIp::resolve($request, $this->config);
        $required = static function (string $name, string $message) use ($field): string {
            return $field($name) ?? throw ApiError::message($message);
        };

        return match (true) {
            $grantType === 'refresh_token' => $this->refreshLogin($required('refresh_token', 'refresh_token cannot be blank'), $field('client_id'), $ip),
            $grantType === 'password' && $this->config->bool('sso_enabled') && $this->config->bool('sso_only') => throw ApiError::message('SSO sign-in is required'),
            $grantType === 'password' => $this->passwordLogin(
                clientId: $required('client_id', 'client_id cannot be blank'),
                password: $required('password', 'password cannot be blank'),
                scope: $required('scope', 'scope cannot be blank'),
                username: $required('username', 'username cannot be blank'),
                device: [
                    $required('device_identifier', 'device_identifier cannot be blank'),
                    $required('device_name', 'device_name cannot be blank'),
                    $required('device_type', 'device_type cannot be blank'),
                ],
                authRequest: $field('authrequest'),
                ip: $ip,
            ),
            $grantType === 'client_credentials' => $this->apiKeyLogin(
                clientId: $required('client_id', 'client_id cannot be blank'),
                clientSecret: $required('client_secret', 'client_secret cannot be blank'),
                scope: $required('scope', 'scope cannot be blank'),
                field: $field,
                ip: $ip,
            ),
            $grantType === 'authorization_code' && $this->config->bool('sso_enabled') => throw ApiError::message('SSO sign-in is not implemented yet'),
            $grantType === 'authorization_code' => throw ApiError::message('SSO sign-in is not available'),
            $grantType === 'send_access' => $this->sendAccessLogin(
                fn (): string => $required('client_id', 'client_id cannot be blank'),
                // Fields without aliases.
                Body::optionalFormField($data, ['send_id']),
                Body::optionalFormField($data, ['password_hash_b64']),
                $ip,
            ),
            default => throw ApiError::message('Invalid type'),
        };
    }

    /**
     * The send_access grant (auth::send::SendTokens): a two-minute token for
     * the Send behind an access id, checked against its password.
     *
     * @param  callable(): string  $clientId  Required, but checked after the rate limit
     *
     * @throws ApiError
     */
    private function sendAccessLogin(callable $clientId, ?string $accessId, ?string $password, string $ip): Response
    {
        $this->limiter->check('unauthenticated', $ip);
        $clientId();
        $accessId ?? throw ApiError::message('send_id cannot be blank');

        $invalid = static fn (string $log, string $type): ApiError => ApiError::json(
            Json::sorted(['kind' => 'expected_server', 'error' => 'invalid_grant', 'send_access_error_type' => $type]),
            $log,
            404,
        );

        $sendId = Send::uuidFromAccessId($accessId) ?? throw $invalid("Can't convert {$accessId}", 'send_id_invalid');
        $send = Send::findByUuid($sendId) ?? throw $invalid("Can't find {$sendId}", 'send_id_invalid');

        if ($send->max_access_count !== null && $send->access_count >= $send->max_access_count) {
            throw $invalid("Send {$sendId}, max access reached", 'send_id_invalid');
        }
        if (! $send->isAccessible()) {
            throw $invalid("Send {$sendId}, not accessible", 'send_id_invalid');
        }

        if ($send->password_hash !== null) {
            if ($password === null) {
                throw ApiError::json(
                    Json::sorted(['kind' => 'expected_server', 'error' => 'invalid_request', 'send_access_error_type' => 'password_hash_b64_required']),
                    'Password required',
                );
            }
            if (! $send->checkPassword($password)) {
                throw $invalid("Send {$sendId}, Invalid password from {$ip}", 'password_hash_b64_invalid');
            }
        }

        $now = Dates::now()->getTimestamp();
        $claims = ['nbf' => $now, 'exp' => $now + 120, 'iss' => $this->jwt->issuer(JwtIssuer::Send), 'sub' => $sendId];

        return Json::response(Json::sorted([
            'access_token' => $this->jwt->encode($claims),
            'expires_in' => $claims['exp'] - Dates::now()->getTimestamp(),
            'token_type' => 'Bearer',
            'scope' => 'api.send.access',
        ]));
    }

    /**
     * POST /identity/accounts/prelogin
     */
    public function postPrelogin(Request $request): Response
    {
        return $this->prelogin($request);
    }

    /**
     * POST /identity/accounts/prelogin/password
     */
    public function preloginPassword(Request $request): Response
    {
        return $this->prelogin($request);
    }

    /**
     * POST /identity/accounts/register/send-verification-email: without mail
     * verification the token comes back directly, as JSON only when the
     * client asks for it.
     */
    public function registerVerificationEmail(Request $request): Response
    {
        $body = Body::json($request);
        $email = $body->string('email');
        $name = $body->optionalString('name');

        $this->limiter->check('unauthenticated', ClientIp::resolve($request, $this->config));

        // Signup must be allowed, or an invitation exist.
        if (! ($this->config->isSignupAllowed($email)
            || (! $this->config->mailEnabled() && Invitation::findByMail($email) !== null))) {
            throw ApiError::message('Registration not allowed or user already exists');
        }

        $sendMail = $this->config->mailEnabled() && $this->config->bool('signups_verify');
        $now = Dates::now()->getTimestamp();
        // RegisterVerifyClaims, valid for 30 minutes.
        $token = $this->jwt->encode([
            'nbf' => $now,
            'exp' => $now + 1_800,
            'iss' => $this->jwt->issuer(JwtIssuer::RegisterVerify),
            'sub' => $email,
            'name' => $name,
            'verified' => $sendMail,
        ]);

        if ($sendMail) {
            $user = User::findByMail($email);
            if ($user !== null && $user->private_key !== null) {
                // Hide that the account exists: the mail path takes about as long.
                usleep(random_int(900, 1_100) * 1_000);
            } else {
                Mail::send('register_verify_email', $email, ['token' => $token]);
            }

            return (new EmptyResponse)->setStatusCode(204);
        }

        return Accept::prefersJson($request)
            ? Json::response($token)
            : new Response($token, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * POST /identity/accounts/register/finish
     */
    public function registerFinish(Request $request): Response
    {
        $data = RegisterData::fromFields(Body::json($request));
        $this->limiter->check('unauthenticated', ClientIp::resolve($request, $this->config));

        return $this->accounts->register($data);
    }

    /**
     * Invalid or missing refresh tokens answer {"error":"invalid_grant"}, which the clients look for.
     */
    private function refreshLogin(string $refreshToken, ?string $clientId, string $ip): Response
    {
        try {
            [$device, $tokens] = AuthTokens::refresh($refreshToken, $clientId, $ip, $this->jwt, $this->config);
        } catch (ApiError $error) {
            throw ApiError::json(['error' => 'invalid_grant'], "Unable to refresh login credentials: {$error->getMessage()}");
        }

        // Saved to track usage and the new status.
        $device->save();

        return Json::response(Json::sorted([
            'refresh_token' => $tokens->refreshToken(),
            'access_token' => $tokens->accessToken(),
            'expires_in' => $tokens->expiresIn(),
            'token_type' => 'Bearer',
            'scope' => $tokens->scope(),
        ]));
    }

    /**
     * @param  array{string, string, string}  $device  Identifier, name and type
     */
    private function passwordLogin(string $clientId, string $password, string $scope, string $username, array $device, ?string $authRequest, string $ip): Response
    {
        AuthMethod::Password->checkScope($scope);
        $this->limiter->check('login', $ip);

        $username = trim($username);
        $logUsername = addcslashes($username, "\0..\37\"'\\");
        $user = User::findByMail($username)
            ?? throw ApiError::message('Username or password is incorrect. Try again', logMessage: "IP: {$ip}. Username: {$logUsername}.");

        if ($authRequest !== null) {
            throw ApiError::message('Login with a device is not implemented yet');
        }
        if (! $user->checkValidPassword($password)) {
            throw ApiError::message('Username or password is incorrect. Try again', logMessage: "IP: {$ip}. Username: {$logUsername}.");
        }
        if (! $user->enabled) {
            throw ApiError::message('This user has been disabled', logMessage: "IP: {$ip}. Username: {$logUsername}.");
        }

        $this->accounts->kdfUpgrade($user, $password);

        if ($user->verified_at === null && $this->config->mailEnabled() && $this->config->bool('signups_verify')) {
            $this->remindToVerify($user);

            // The login fails until the address is verified.
            throw ApiError::message('Please verify your email before trying again.', logMessage: "IP: {$ip}. Username: {$logUsername}.");
        }

        $loginDevice = $this->getDevice($user, ...$device);
        $this->twofactorAuth($user, $loginDevice, $ip);

        return $this->authenticatedResponse($user, $loginDevice, AuthTokens::new($loginDevice, $user, AuthMethod::Password, $clientId, $this->jwt), $ip);
    }

    /**
     * @param  callable(string): ?string  $field
     */
    private function apiKeyLogin(string $clientId, string $clientSecret, string $scope, callable $field, string $ip): Response
    {
        $this->limiter->check('login', $ip);

        if ($scope === AuthMethod::OrgApiKey->scope()) {
            return $this->organizationApiKeyLogin($clientId, $clientSecret, $ip);
        }
        if ($scope !== AuthMethod::UserApiKey->scope()) {
            throw ApiError::message('Scope not supported');
        }

        // Like upstream, only the user API key logs in a device.
        $device = [];
        foreach (['device_identifier', 'device_name', 'device_type'] as $name) {
            $device[] = $field($name) ?? throw ApiError::message("{$name} cannot be blank");
        }

        $userId = str_starts_with($clientId, 'user.') ? substr($clientId, strlen('user.')) : throw ApiError::message('Malformed client_id', logMessage: "IP: {$ip}.");
        $user = User::findByUuid($userId) ?? throw ApiError::message('Invalid client_id', logMessage: "IP: {$ip}.");

        if (! $user->enabled) {
            throw ApiError::message('This user has been disabled (API key login)', logMessage: "IP: {$ip}. Username: {$user->email}.");
        }
        // API key logins bypass 2FA.
        if (! $user->checkValidApiKey($clientSecret)) {
            throw ApiError::message('Incorrect client_secret', logMessage: "IP: {$ip}. Username: {$user->email}.");
        }

        $loginDevice = $this->getDevice($user, ...$device);
        $this->notifyNewDevice($user, $loginDevice, $ip);

        $claims = LoginClaims::default($loginDevice, $user, AuthMethod::UserApiKey, $clientId, $this->jwt);
        $loginDevice->save();

        // No refresh token: the CLI logs in again when the access token expires.
        return Json::response(Json::sorted([
            'access_token' => $claims->token($this->jwt),
            'expires_in' => $claims->expiresIn(),
            'token_type' => 'Bearer',
            'Key' => $user->akey,
            'PrivateKey' => $user->private_key,
            'Kdf' => $user->client_kdf_type,
            'KdfIterations' => $user->client_kdf_iter,
            'KdfMemory' => $user->client_kdf_memory,
            'KdfParallelism' => $user->client_kdf_parallelism,
            'ForcePasswordReset' => false,
            'scope' => AuthMethod::UserApiKey->scope(),
            'AccountKeys' => self::accountKeys($user),
            'UserDecryptionOptions' => self::decryptionOptions($user),
        ]));
    }

    private function authenticatedResponse(User $user, Device $device, AuthTokens $tokens, string $ip, ?string $twofactorToken = null): Response
    {
        $this->notifyNewDevice($user, $device, $ip);

        // Pushing to mobile devices is not ported yet; without PUSH_ENABLED Vaultwarden skips it too.
        if (! $device->isNew() && $this->config->bool('push_enabled') && $device->isPushDevice()) {
            logger()->warning('Registering push devices is not implemented yet.');
        }

        // Saved to track usage and the new status.
        $device->save();

        $result = [
            'access_token' => $tokens->accessToken(),
            'expires_in' => $tokens->expiresIn(),
            'token_type' => 'Bearer',
            'refresh_token' => $tokens->refreshToken(),
            'PrivateKey' => $user->private_key,
            'Kdf' => $user->client_kdf_type,
            'KdfIterations' => $user->client_kdf_iter,
            'KdfMemory' => $user->client_kdf_memory,
            'KdfParallelism' => $user->client_kdf_parallelism,
            'ForcePasswordReset' => false,
            'MasterPasswordPolicy' => MasterPasswordPolicy::forIdentity($user, $this->config),
            'scope' => $tokens->scope(),
            'AccountKeys' => self::accountKeys($user),
            'UserDecryptionOptions' => self::decryptionOptions($user),
        ];
        if ($user->akey !== '') {
            $result['Key'] = $user->akey;
        }
        if ($twofactorToken !== null) {
            $result['TwoFactorToken'] = $twofactorToken;
        }

        logger()->info("User {$user->email} logged in successfully. IP: {$ip}");

        return Json::response(Json::sorted($result));
    }

    /**
     * The device of the login, created (without touching updated_at) when it is new.
     */
    private function getDevice(User $user, string $identifier, string $name, string $type): Device
    {
        // iOS sends "iOS" here, the others a number; unknown means Unknown Browser.
        $atype = preg_match('/^[+-]?[0-9]+$/D', $type) && (int) $type >= -2_147_483_648 && (int) $type <= 2_147_483_647
            ? (int) $type : 14;

        $device = Device::findByUuidAndUser($identifier, $user->uuid);
        if ($device === null) {
            $device = Device::new($identifier, $user->uuid, $name, $atype);
            $device->save(['update_time' => false]);
        }

        return $device;
    }

    /**
     * Without two-factor methods the organization policy may revoke memberships;
     * the two-factor login itself is not ported yet.
     */
    private function twofactorAuth(User $user, Device $device, string $ip): void
    {
        if (TwoFactor::findByUser($user->uuid)->isEmpty()) {
            OrgPolicy::enforceTwoFactorForUser($user);

            return;
        }

        throw ApiError::message('Two-factor login is not implemented yet');
    }

    /**
     * identity::organization_api_key_login: a token for the public API, for
     * one hour; no device, no refresh.
     */
    private function organizationApiKeyLogin(string $clientId, string $clientSecret, string $ip): Response
    {
        $orgId = str_starts_with($clientId, 'organization.') ? substr($clientId, strlen('organization.')) : throw ApiError::message('Malformed client_id', logMessage: "IP: {$ip}.");
        $apiKey = OrganizationApiKey::findByOrgUuid($orgId) ?? throw ApiError::message('Invalid client_id', logMessage: "IP: {$ip}.");
        if (! $apiKey->checkValidApiKey($clientSecret)) {
            throw ApiError::message('Incorrect client_secret', logMessage: "IP: {$ip}. Organization: {$apiKey->org_uuid}.");
        }

        // OrgApiKeyLoginJwtClaims
        $now = time();
        $token = $this->jwt->encode([
            'nbf' => $now,
            'exp' => $now + 3600,
            'iss' => $this->jwt->issuer(JwtIssuer::OrgApiKey),
            'sub' => $apiKey->uuid,
            'client_id' => "organization.{$apiKey->org_uuid}",
            'client_sub' => $apiKey->org_uuid,
            'scope' => [AuthMethod::OrgApiKey->scope()],
        ]);

        return Json::response(Json::sorted(['access_token' => $token, 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => AuthMethod::OrgApiKey->scope()]));
    }

    /**
     * Mail about a login from a new device; failing it only stops the login with REQUIRE_DEVICE_EMAIL.
     */
    private function notifyNewDevice(User $user, Device $device, string $ip): void
    {
        if (! $this->config->mailEnabled() || ! $device->isNew()) {
            return;
        }

        try {
            Mail::send('new_device_logged_in', $user->email, ['ip' => $ip, 'device' => $device->name]);
        } catch (ApiError $error) {
            logger()->error("Error sending new device email: {$error->getMessage()}");
            if ($this->config->bool('require_device_email')) {
                throw ApiError::message('Could not send login notification email. Please contact your administrator.');
            }
        }
    }

    /**
     * Send another verification mail now and then while the email is unverified.
     */
    private function remindToVerify(User $user): void
    {
        $now = Dates::now();
        if ($user->last_verifying_at !== null
            && $now->getTimestamp() - $user->last_verifying_at->getTimestamp() <= $this->config->int('signups_verify_resend_time')) {
            return;
        }

        $limit = $this->config->int('signups_verify_resend_limit');
        if ($limit !== 0 && $user->login_verify_count >= $limit) {
            return;
        }

        $user->last_verifying_at = $now;
        $user->login_verify_count++;
        try {
            $user->save();
        } catch (ApiError $error) {
            logger()->error("Error updating user: {$error->getMessage()}");
        }

        try {
            Mail::send('verify_email', $user->email);
        } catch (ApiError $error) {
            logger()->error("Error auto-sending email verification email: {$error->getMessage()}");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function accountKeys(User $user): ?array
    {
        if ($user->private_key === null) {
            return null;
        }

        return Json::sorted([
            'publicKeyEncryptionKeyPair' => [
                'wrappedPrivateKey' => $user->private_key,
                'publicKey' => $user->public_key,
                'Object' => 'publicKeyEncryptionKeyPair',
            ],
            'Object' => 'privateKeys',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decryptionOptions(User $user): array
    {
        $hasMasterPassword = $user->password_hash !== '';

        return Json::sorted([
            'HasMasterPassword' => $hasMasterPassword,
            'MasterPasswordUnlock' => $hasMasterPassword ? [
                'Kdf' => [
                    'KdfType' => $user->client_kdf_type,
                    'Iterations' => $user->client_kdf_iter,
                    'Memory' => $user->client_kdf_memory,
                    'Parallelism' => $user->client_kdf_parallelism,
                ],
                // Named inconsistently upstream; clients move to the wrapped variant.
                'MasterKeyEncryptedUserKey' => $user->akey,
                'MasterKeyWrappedUserKey' => $user->akey,
                'Salt' => $user->email,
            ] : null,
            'Object' => 'userDecryptionOptions',
        ]);
    }

    private function prelogin(Request $request): Response
    {
        $email = Body::json($request)->string('email');

        return $this->accounts->prelogin($email, ClientIp::resolve($request, $this->config));
    }
}
