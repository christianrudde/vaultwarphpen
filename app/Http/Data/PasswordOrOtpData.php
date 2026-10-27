<?php

namespace App\Http\Data;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\HttpStatus;
use App\Models\TwoFactor;
use App\Models\User;
use App\Support\Dates;
use App\Support\JsonValue;

/**
 * PasswordOrOtpData in src/api/mod.rs: a protected action is confirmed with
 * the master password hash or, for users without one, a mailed code.
 */
final readonly class PasswordOrOtpData
{
    public function __construct(
        public ?string $masterPasswordHash,
        public ?string $otp,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromFields(Fields $fields): self
    {
        return new self(
            masterPasswordHash: $fields->optionalString('masterPasswordHash', 'MasterPasswordHash'),
            otp: $fields->optionalString('otp'),
        );
    }

    /**
     * @param  bool  $deleteIfValid  Whether the code is used up; some callers need it twice
     *
     * @throws ApiError
     */
    public function validate(User $user, bool $deleteIfValid, Config $config): void
    {
        match (true) {
            $this->masterPasswordHash !== null && $this->otp === null => $user->checkValidPassword($this->masterPasswordHash)
                ?: throw ApiError::message('Invalid password'),
            $this->masterPasswordHash === null && $this->otp !== null => self::validateOtp($this->otp, $user->uuid, $deleteIfValid, $config),
            default => throw ApiError::message('No validation provided'),
        };
    }

    /**
     * two_factor::protected_actions::validate_protected_action_otp
     *
     * @throws ApiError
     */
    private static function validateOtp(string $otp, string $userUuid, bool $deleteIfValid, Config $config): bool
    {
        $action = TwoFactor::findByUserAndType($userUuid, TwoFactor::TYPE_PROTECTED_ACTIONS)
            ?? throw ApiError::message('Protected action token not found, try sending the code again or restart the process');

        // ProtectedActionData: the code, when it was sent (UNIX seconds) and the failed attempts.
        [$ok, $data] = JsonValue::decode($action->data);
        $data = $ok && JsonValue::isObject($data) ? (array) $data : [];
        $token = $data['token'] ?? null;
        $sent = $data['token_sent'] ?? null;
        $attempts = $data['attempts'] ?? null;
        if (! is_string($token) || ! is_int($sent) || ! is_int($attempts) || $attempts < 0) {
            throw ApiError::message('Could not decode ProtectedActionData from string');
        }

        // Too many attempts; the record stays to keep count.
        if ($attempts >= $config->int('email_attempts_limit')) {
            throw ApiError::message('Token has expired');
        }

        if (Dates::now()->getTimestamp() - $sent > $config->int('email_expiration_time')) {
            $action->deleteTwoFactor();
            throw ApiError::message('Token has expired');
        }

        if (! hash_equals($token, $otp)) {
            $action->data = json_encode(['token' => $token, 'token_sent' => $sent, 'attempts' => $attempts + 1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $action->save();
            throw ApiError::message('Token is invalid');
        }

        if ($deleteIfValid) {
            $action->deleteTwoFactor();
        }

        return true;
    }
}
