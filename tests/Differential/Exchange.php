<?php

namespace Tests\Differential;

/**
 * One server's answer to a request.
 */
final readonly class Exchange
{
    /**
     * @param  array<string, string>  $headers  Lower-case names, multiple values joined with ", "
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        return str_starts_with((string) $this->header('Content-Type'), 'application/json');
    }
}
