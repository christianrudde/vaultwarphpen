<?php

namespace App\Auth\Sso;

use RuntimeException;

/**
 * RequestTokenError of oauth2: why a token request failed. The message is
 * its Debug form, which Vaultwarden reports for the code exchange; the
 * refresh reports its Display form.
 */
final class TokenRequestError extends RuntimeException
{
    public function __construct(string $debug, public readonly string $display)
    {
        parent::__construct($debug);
    }
}
