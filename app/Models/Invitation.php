<?php

namespace App\Models;

use App\Http\ApiError;
use App\Support\EmailAddress;
use Illuminate\Database\QueryException;

/**
 * An admin invitation (Invitation in src/db/models/user.rs).
 *
 * @property string $email
 */
final class Invitation extends Model
{
    protected $table = 'invitations';

    protected $primaryKey = 'email';

    public static function new(string $email): self
    {
        return new self(['email' => mb_strtolower($email)]);
    }

    /**
     * Insert unless it exists.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        if (! EmailAddress::isValid($this->email)) {
            throw ApiError::message('Invitation email '.addcslashes($this->email, "\0..\37\"\\").' is not a valid email address');
        }

        try {
            self::query()->insertOrIgnore(['email' => $this->email]);
        } catch (QueryException) {
            throw ApiError::message('Error saving invitation');
        }
        $this->exists = true;

        return true;
    }

    public static function findByMail(string $mail): ?self
    {
        return self::query()->where('email', mb_strtolower($mail))->first();
    }

    /**
     * Remove the invitation of an email; whether there was one.
     */
    public static function take(string $mail): bool
    {
        $invitation = self::findByMail($mail);
        if ($invitation === null) {
            return false;
        }

        try {
            return self::query()->where('email', $invitation->email)->delete() > 0;
        } catch (QueryException) {
            return false;
        }
    }
}
