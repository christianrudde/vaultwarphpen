<?php

namespace App\Auth;

use App\Http\ApiError;

/**
 * How a session was started (auth::AuthMethod); the subject of refresh tokens.
 */
enum AuthMethod: string
{
    case OrgApiKey = 'orgapikey';
    case Password = 'password';
    case Sso = 'sso';
    case UserApiKey = 'userapikey';

    public function scope(): string
    {
        return match ($this) {
            self::OrgApiKey => 'api.organization',
            self::UserApiKey => 'api',
            self::Password, self::Sso => 'api offline_access',
        };
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return preg_split('/\s+/', $this->scope(), flags: PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The scope a login request asks for must be the one of its method.
     *
     * @throws ApiError
     */
    public function checkScope(?string $scope): string
    {
        return match (true) {
            $scope === null => throw ApiError::message('Missing scope'),
            $scope === $this->scope() => $scope,
            default => throw ApiError::message('Scope ('.addcslashes($scope, "\0..\37\"'\\").') not supported'),
        };
    }
}
