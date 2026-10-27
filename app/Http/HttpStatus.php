<?php

namespace App\Http;

use RuntimeException;

/**
 * A request that Rocket rejects before the handler runs, such as a body
 * guard that failed: answered by the catchers with only the status.
 */
final class HttpStatus extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $reason = '',
    ) {
        parent::__construct($reason);
    }

    /**
     * Rocket logs failed guards as warnings.
     */
    public function report(): void
    {
        logger()->warning("Request failed with status {$this->status}: {$this->getMessage()}");
    }
}
