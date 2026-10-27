<?php

namespace Tests\Differential;

use RuntimeException;

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

    /**
     * A value of the JSON body, by dotted path; the whole body without one.
     */
    public function json(?string $path = null): mixed
    {
        $value = json_decode($this->body, true);
        foreach ($path === null ? [] : explode('.', $path) as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * A string the flow needs to go on; anything else is a broken setup, not a
     * difference worth comparing.
     */
    public function jsonString(string $path): string
    {
        $value = $this->json($path);

        return is_string($value) ? $value : throw new RuntimeException("No string at {$path} in a {$this->status} response: {$this->body}");
    }

    /**
     * The same answer with another body, to blank out values that differ by nature.
     */
    public function withBody(string $body): self
    {
        return new self($this->status, $this->headers, $body);
    }

    public function isJson(): bool
    {
        return str_starts_with((string) $this->header('Content-Type'), 'application/json');
    }
}
