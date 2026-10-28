<?php

namespace App\Auth\Sso;

use RuntimeException;

/**
 * RequestTokenError of oauth2: why a token request failed, in its Debug form, as Vaultwarden logs and reports it.
 */
final class TokenRequestError extends RuntimeException {}
