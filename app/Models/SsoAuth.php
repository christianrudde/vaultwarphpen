<?php

namespace App\Models;

use App\Auth\Sso\AuthenticatedUser;
use App\Http\ApiError;
use App\Http\Json;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use stdClass;

/**
 * An SSO login in progress (src/db/models/sso_auth.rs), from the redirect
 * to the provider until the login with its code. The code response error
 * and the authenticated user are stored as JSON, like serde writes them.
 *
 * @property string $state
 * @property string $client_challenge
 * @property string $nonce
 * @property string $redirect_uri
 * @property ?string $code_response
 * @property ?string $code_response_error
 * @property ?string $auth_response
 * @property DateTimeImmutable $created_at
 * @property DateTimeImmutable $updated_at
 * @property ?string $binding_hash
 */
final class SsoAuth extends Model
{
    /**
     * SSO_AUTH_EXPIRATION in src/sso.rs, in minutes.
     */
    public const int EXPIRATION = 10;

    protected $table = 'sso_auth';

    protected $primaryKey = 'state';

    protected function casts(): array
    {
        return [
            'created_at' => NaiveDateTime::class,
            'updated_at' => NaiveDateTime::class,
        ];
    }

    public static function new(string $state, string $clientChallenge, string $nonce, string $redirectUri, ?string $bindingHash): self
    {
        $now = Dates::now();
        $auth = new self;
        $auth->state = $state;
        $auth->client_challenge = $clientChallenge;
        $auth->nonce = $nonce;
        $auth->redirect_uri = $redirectUri;
        $auth->created_at = $now;
        $auth->updated_at = $now;
        $auth->code_response = null;
        $auth->code_response_error = null;
        $auth->auth_response = null;
        $auth->binding_hash = $bindingHash;

        return $auth;
    }

    /**
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        $this->upsertRow(['state'], 'Error saving SSO auth');

        return true;
    }

    /**
     * A login started within SSO_AUTH_EXPIRATION.
     */
    public static function find(string $state): ?self
    {
        return self::query()->where('state', $state)->where('created_at', '>=', self::oldest())->first();
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code_response', $code)->where('created_at', '>=', self::oldest())->first();
    }

    /**
     * @throws ApiError
     */
    public function deleteAuth(): void
    {
        try {
            self::query()->where('state', $this->state)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting sso_auth');
        }
    }

    /**
     * Deletes the logins that were not completed in time.
     *
     * @throws ApiError
     */
    public static function deleteExpired(): void
    {
        try {
            self::query()->where('created_at', '<', self::oldest())->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting expired SSO nonce');
        }
    }

    /**
     * OIDCCodeResponseError: what the provider answered instead of a code.
     */
    public function setCodeResponseError(?string $error, ?string $description): void
    {
        $this->code_response_error = $error === null ? null : Json::encode(['error' => $error, 'error_description' => $description]);
    }

    /**
     * @return array{string, ?string}|null The error and its description
     */
    public function codeResponseError(): ?array
    {
        $data = $this->code_response_error === null ? null : json_decode($this->code_response_error);
        if (! $data instanceof stdClass || ! is_string($data->error ?? null)) {
            return null;
        }
        $description = $data->error_description ?? null;

        return [$data->error, is_string($description) ? $description : null];
    }

    public function authenticatedUser(): ?AuthenticatedUser
    {
        return $this->auth_response === null ? null : AuthenticatedUser::fromJson($this->auth_response);
    }

    private static function oldest(): string
    {
        return NaiveDateTime::format(Dates::now()->modify('-'.self::EXPIRATION.' minutes'));
    }
}
