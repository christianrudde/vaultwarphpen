<?php

namespace App\Auth\Webauthn;

use App\Http\ApiError;
use RuntimeException;

/**
 * webauthn_rs_core::error::WebauthnError, by the name of its variant.
 * Vaultwarden converts it with `?`, so the client sees "Webauthn".
 */
final class WebauthnError extends RuntimeException
{
    public function toApiError(): ApiError
    {
        return ApiError::message('Webauthn', logMessage: $this->getMessage());
    }
}
