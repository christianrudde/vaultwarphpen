<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\QueryException;

/**
 * A Duo login between the redirect to Duo and the code coming back
 * (src/db/models/two_factor_duo_context.rs): its state, the user and the
 * nonce, until the UNIX time exp. A context is created once and deleted.
 *
 * @property string $state
 * @property string $user_email
 * @property string $nonce
 * @property int $exp
 */
final class TwoFactorDuoContext extends Model
{
    protected $table = 'twofactor_duo_ctx';

    protected $primaryKey = 'state';

    protected function casts(): array
    {
        return ['exp' => 'integer'];
    }

    public static function findByState(string $state): ?self
    {
        return self::query()->where('state', $state)->first();
    }

    /**
     * @throws ApiError
     */
    public static function store(string $state, string $userEmail, string $nonce, int $ttl): void
    {
        if (self::findByState($state) !== null) {
            return;
        }
        try {
            self::query()->insert(['state' => $state, 'user_email' => $userEmail, 'nonce' => $nonce, 'exp' => time() + $ttl]);
        } catch (QueryException) {
            throw ApiError::message('Error saving context to twofactor_duo_ctx');
        }
    }

    /**
     * @throws ApiError
     */
    public function deleteContext(): void
    {
        try {
            self::query()->where('state', $this->state)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting from twofactor_duo_ctx');
        }
    }

    /**
     * purge_expired_duo_contexts
     */
    public static function purgeExpired(): void
    {
        foreach (self::query()->where('exp', '<', time())->get() as $context) {
            try {
                $context->deleteContext();
            } catch (ApiError) {
                // Ignored like upstream.
            }
        }
    }
}
