<?php

namespace App\Http\Controllers;

use App\Auth\Headers;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Auth\RsaKeys;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\KdfData;
use App\Http\Data\RegisterData;
use App\Http\Json;
use App\Mail\Mail;
use App\Models\EmergencyAccess;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Support\Dates;
use App\Support\RateLimiter;
use App\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * src/api/core/accounts.rs
 */
final readonly class AccountsController
{
    private const int KDF_PBKDF2 = 0;

    private const int KDF_ARGON2ID = 1;

    /**
     * Settings for unknown emails, the default twice for more weight (like upstream).
     */
    private const array UNKNOWN_EMAIL_KDF = [
        [User::CLIENT_KDF_TYPE_DEFAULT, User::CLIENT_KDF_ITER_DEFAULT, null, null],
        [User::CLIENT_KDF_TYPE_DEFAULT, User::CLIENT_KDF_ITER_DEFAULT, null, null],
        [self::KDF_PBKDF2, 100_000, null, null],
        [self::KDF_PBKDF2, 5_000, null, null],
        [self::KDF_ARGON2ID, 3, 64, 4],
        [self::KDF_ARGON2ID, 6, 32, 4],
    ];

    public function __construct(
        private Config $config,
        private Jwt $jwt,
        private RsaKeys $keys,
        private RateLimiter $limiter,
    ) {}

    /**
     * accounts::prelogin: the KDF settings of an email. An unknown email gets
     * settings derived from it, the same on every request.
     */
    public function prelogin(string $email, string $ip): Response
    {
        $this->limiter->check('unauthenticated', $ip);

        $email = mb_strtolower(trim($email));
        $user = User::findByMail($email);
        [$type, $iterations, $memory, $parallelism] = $user !== null
            ? [$user->client_kdf_type, $user->client_kdf_iter, $user->client_kdf_memory, $user->client_kdf_parallelism]
            : self::UNKNOWN_EMAIL_KDF[$this->keys->preloginKdfIndex($email, count(self::UNKNOWN_EMAIL_KDF))];

        return Json::response(Json::sorted([
            'kdf' => $type,
            'kdfIterations' => $iterations,
            'kdfMemory' => $memory,
            'kdfParallelism' => $parallelism,
            'kdfSettings' => [
                'iterations' => $iterations,
                'kdfType' => $type,
                'memory' => $memory,
                'parallelism' => $parallelism,
            ],
            'salt' => null,
        ]));
    }

    /**
     * accounts::register: create the user, or complete an invited one.
     *
     * @throws ApiError
     */
    public function register(RegisterData $data): Response
    {
        $email = mb_strtolower($data->email);
        $name = null;
        $pendingEmergencyAccess = false;

        if ($data->unprocessable()) {
            throw ApiError::message('Unexpected RegisterData format', 422);
        }

        // The verification tokens decide which kind of registration this is.
        $tokens = [
            $data->emailVerificationToken !== null,
            $data->acceptEmergencyAccessId !== null,
            $data->acceptEmergencyAccessInviteToken !== null,
            $data->organizationUserId !== null,
            $data->orgInviteToken !== null,
        ];
        if ($tokens === [true, false, false, false, false]) {
            // Normal registration
            $claims = $this->jwt->decode((string) $data->emailVerificationToken, JwtIssuer::RegisterVerify);
            if (($claims['sub'] ?? null) !== $data->email) {
                throw ApiError::message('Email verification token does not match email');
            }
            $name = is_string($claims['name'] ?? null) ? $claims['name'] : null;
            $emailVerified = ($claims['verified'] ?? false) === true;
        } elseif ($tokens === [false, true, true, false, false]) {
            // Emergency access registration
            if (! $this->config->bool('emergency_access_allowed')) {
                throw ApiError::message('Emergency access is not enabled.');
            }
            $claims = $this->jwt->decode((string) $data->acceptEmergencyAccessInviteToken, JwtIssuer::EmergencyAccessInvite);
            if (($claims['email'] ?? null) !== $data->email) {
                throw ApiError::message('Claim email does not match email');
            }
            if (($claims['emer_id'] ?? null) !== $data->acceptEmergencyAccessId) {
                throw ApiError::message('Claim emer_id does not match accept_emergency_access_id');
            }
            $pendingEmergencyAccess = true;
            $emailVerified = true;
        } elseif ($tokens === [false, false, false, true, true]) {
            // Organization invitation
            $claims = $this->jwt->decode((string) $data->orgInviteToken, JwtIssuer::Invite, fields: Jwt::INVITE_CLAIMS);
            if (($claims['email'] ?? null) !== $data->email) {
                throw ApiError::message('Claim email does not match email');
            }
            if (($claims['member_id'] ?? null) !== $data->organizationUserId) {
                throw ApiError::message('Claim org_user_id does not match organization_user_id');
            }
            $emailVerified = true;
        } else {
            throw ApiError::message('Registration is missing required parameters');
        }

        // Like upstream Bitwarden; long names also make the tokens too large.
        if ($name !== null && strlen($name) > 50) {
            throw ApiError::message('The field Name must be a string with a maximum length of 50.');
        }

        // Checked before the invitation is used, so that a failure can be retried.
        $passwordHint = self::cleanPasswordHint($data->masterPasswordHint);
        $this->enforcePasswordHintSetting($passwordHint);

        $user = User::findByMail($email);
        if ($user !== null) {
            if ($user->password_hash !== '') {
                throw ApiError::message('Registration not allowed or user already exists');
            }

            if ($data->orgInviteToken !== null) {
                $claims = $this->jwt->decode($data->orgInviteToken, JwtIssuer::Invite, fields: Jwt::INVITE_CLAIMS);
                if (($claims['email'] ?? null) !== $email) {
                    throw ApiError::message('Registration email does not match invite email');
                }
                // A valid invitation verifies the email.
                $emailVerified = true;
            } elseif (Invitation::take($email)) {
                Membership::acceptUserInvitations($user->uuid);
            } elseif (! $this->config->isSignupAllowed($email)
                && ! ($this->config->bool('emergency_access_allowed') && EmergencyAccess::findInvitedByGranteeEmail($email) !== null)) {
                throw ApiError::message('Registration not allowed or user already exists');
            }
        } elseif (Invitation::take($email) || $this->config->isSignupAllowed($email) || $pendingEmergencyAccess) {
            // The invitation comes first: the admin can invite anyone, whatever the signup rules.
            $user = User::new($email);
        } else {
            throw ApiError::message('Registration not allowed or user already exists');
        }

        // No invitation may linger.
        Invitation::take($email);

        self::setKdfData($user, $data->kdf);
        $user->setPassword($data->hash, $data->key, resetSecurityStamp: true, allowNextRoute: null);
        $user->password_hint = $passwordHint;

        if ($name !== null) {
            $user->name = $name;
        }
        if ($data->keys !== null) {
            $user->private_key = $data->keys['encryptedPrivateKey'];
            $user->public_key = $data->keys['publicKey'];
        }
        if ($emailVerified) {
            $user->verified_at = Dates::now();
        }

        if ($this->config->mailEnabled()) {
            try {
                if ($this->config->bool('signups_verify') && ! $emailVerified) {
                    $user->last_verifying_at = $user->created_at;
                    Mail::sendWelcomeMustVerify($user->email, $user->uuid);
                } else {
                    Mail::sendWelcome($user->email);
                }
            } catch (Throwable $error) {
                logger()->error("Error sending welcome email: {$error->getMessage()}");
            }
        }

        $user->save();

        // Open emergency access invitations are accepted right away.
        if (! $this->config->mailEnabled() && $this->config->bool('emergency_access_allowed')) {
            foreach (EmergencyAccess::findAllInvitedByGranteeEmail($user->email) as $invite) {
                try {
                    $invite->acceptInvite($user->uuid, $user->email);
                } catch (ApiError) {
                    // Like `.ok()`: ignored.
                }
            }
        }

        return Json::response(['object' => 'registerFinish']);
    }

    /**
     * GET /api/accounts/profile
     */
    public function profile(Headers $headers): Response
    {
        return Json::response($headers->user->toApi());
    }

    /**
     * PUT /api/accounts/profile
     */
    public function putProfile(Headers $headers, Request $request): Response
    {
        return $this->postProfile($headers, $request);
    }

    /**
     * POST /api/accounts/profile: only the name can change; the culture is always en-US.
     */
    public function postProfile(Headers $headers, Request $request): Response
    {
        $name = Body::json($request)->string('name');

        // Like upstream Bitwarden; long names also make the tokens too large.
        if (strlen($name) > 50) {
            throw ApiError::message('The field Name must be a string with a maximum length of 50.');
        }

        $user = $headers->user;
        $user->name = $name;
        $user->save();

        return Json::response($user->toApi());
    }

    /**
     * PUT /api/accounts/avatar: a color like #a1b2c3, or none.
     */
    public function putAvatar(Headers $headers, Request $request): Response
    {
        $color = Body::json($request)->optionalString('avatarColor');
        if ($color !== null && strlen($color) !== 7) {
            throw ApiError::message('The field AvatarColor must be a HTML/Hex color code with a length of 7 characters');
        }

        $user = $headers->user;
        $user->avatar_color = $color;
        $user->save();

        return Json::response($user->toApi());
    }

    /**
     * GET /api/users/{user_id}/public-key
     */
    public function getPublicKeys(Headers $headers, Request $request, string $userId): Response
    {
        $user = User::findByUuid(Uuid::parameter($request, $userId));
        if ($user === null) {
            throw ApiError::message("User doesn't exist", 404);
        }
        if ($user->public_key === null) {
            throw ApiError::message('User has no public_key', 404);
        }

        return Json::response(Json::sorted([
            'userId' => $user->uuid,
            'publicKey' => $user->public_key,
            'object' => 'userKey',
        ]));
    }

    /**
     * GET /api/accounts/keys: the SDK reads a 404 as "no key pair yet".
     */
    public function getKeys(Headers $headers): Response
    {
        $user = $headers->user;
        if ($user->private_key === null || $user->public_key === null) {
            throw ApiError::message('User has no key pair', 404);
        }

        return Json::response(Json::sorted([
            'key' => $user->akey === '' ? null : $user->akey,
            'publicKey' => $user->public_key,
            'privateKey' => $user->private_key,
            'accountKeys' => $user->accountKeysJson(),
            'object' => 'keys',
        ]));
    }

    /**
     * POST /api/accounts/keys: the key pair can only be set once.
     */
    public function postKeys(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $encryptedPrivateKey = $body->string('encryptedPrivateKey');
        $publicKey = $body->string('publicKey');

        $user = $headers->user;
        if ($user->private_key !== null || $user->public_key !== null) {
            throw ApiError::message('User has existing keypair');
        }

        $user->private_key = $encryptedPrivateKey;
        $user->public_key = $publicKey;
        $user->save();

        return Json::response(Json::sorted([
            'privateKey' => $user->private_key,
            'publicKey' => $user->public_key,
            'object' => 'keys',
        ]));
    }

    /**
     * GET /api/accounts/revision-date: milliseconds since the epoch.
     */
    public function revisionDate(Headers $headers): Response
    {
        return Json::response((int) $headers->user->updated_at->format('Uv'));
    }

    /**
     * Validate and apply the client's KDF settings.
     *
     * @throws ApiError
     */
    public static function setKdfData(User $user, KdfData $data): void
    {
        if ($data->kdf === self::KDF_PBKDF2 && $data->kdfIterations < 100_000) {
            throw ApiError::message('PBKDF2 KDF iterations must be at least 100000.');
        }

        if ($data->kdf === self::KDF_ARGON2ID) {
            if ($data->kdfIterations < 1) {
                throw ApiError::message('Argon2 KDF iterations must be at least 1.');
            }
            if ($data->kdfMemory === null) {
                throw ApiError::message('Argon2 memory parameter is required.');
            }
            if ($data->kdfMemory < 15 || $data->kdfMemory > 1024) {
                throw ApiError::message('Argon2 memory must be between 15 MB and 1024 MB.');
            }
            $user->client_kdf_memory = $data->kdfMemory;
            if ($data->kdfParallelism === null) {
                throw ApiError::message('Argon2 parallelism parameter is required.');
            }
            if ($data->kdfParallelism < 1 || $data->kdfParallelism > 16) {
                throw ApiError::message('Argon2 parallelism must be between 1 and 16.');
            }
            $user->client_kdf_parallelism = $data->kdfParallelism;
        } else {
            $user->client_kdf_memory = null;
            $user->client_kdf_parallelism = null;
        }

        $user->client_kdf_iter = $data->kdfIterations;
        $user->client_kdf_type = $data->kdf;
    }

    /**
     * Raise the server-side hash iterations to PASSWORD_ITERATIONS at login.
     *
     * @throws ApiError
     */
    public function kdfUpgrade(User $user, string $passwordHash): void
    {
        $iterations = $this->config->int('password_iterations');
        if ($user->password_iterations >= $iterations) {
            return;
        }

        $user->password_iterations = $iterations;
        $user->setPassword($passwordHash, null, resetSecurityStamp: false, allowNextRoute: null);

        try {
            $user->save();
        } catch (ApiError $error) {
            logger()->error("Error updating user: {$error->getMessage()}");
        }
    }

    /**
     * Blank hints count as none.
     */
    public static function cleanPasswordHint(?string $hint): ?string
    {
        $hint = $hint === null ? null : trim($hint);

        return $hint === '' ? null : $hint;
    }

    /**
     * @throws ApiError
     */
    public function enforcePasswordHintSetting(?string $hint): void
    {
        if ($hint !== null && ! $this->config->bool('password_hints_allowed')) {
            throw ApiError::message('Password hints have been disabled by the administrator. Remove the hint and try again.');
        }
    }
}
