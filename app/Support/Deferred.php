<?php

namespace App\Support;

use Closure;

/**
 * Work Vaultwarden hands to tokio::task::spawn so that the response need not
 * wait for it: run once the application terminates, after the response was
 * sent (PHP-FPM finishes the request first).
 */
final class Deferred
{
    /**
     * @var list<Closure(): void>
     */
    private array $pending = [];

    /**
     * @param  Closure(): void  $callback
     */
    public function add(Closure $callback): void
    {
        $this->pending[] = $callback;
    }

    /**
     * Run what is pending, each callback once.
     */
    public function run(): void
    {
        while (($callback = array_shift($this->pending)) !== null) {
            $callback();
        }
    }
}
