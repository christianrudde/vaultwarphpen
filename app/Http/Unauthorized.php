<?php

namespace App\Http;

use RuntimeException;

/**
 * A request guard that failed with err_handler!: status 401, answered by the
 * catchers. The message is only logged; unlike HTTP exceptions, Laravel
 * reports this one, so the log matches Vaultwarden's.
 */
final class Unauthorized extends RuntimeException
{
    public function report(): void
    {
        logger()->error("Unauthorized Error: {$this->getMessage()}");
    }
}
