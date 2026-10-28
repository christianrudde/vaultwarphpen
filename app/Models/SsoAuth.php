<?php

namespace App\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use DateTimeImmutable;
use Illuminate\Database\QueryException;

/**
 * An SSO login in progress (src/db/models/sso_auth.rs); so far the parts the
 * scheduled job needs.
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

    /**
     * Deletes the logins that were not completed in time.
     *
     * @throws ApiError
     */
    public static function deleteExpired(): void
    {
        $oldest = Dates::now()->modify('-'.self::EXPIRATION.' minutes');
        try {
            self::query()->where('created_at', '<', NaiveDateTime::format($oldest))->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting expired SSO nonce');
        }
    }
}
