<?php

namespace Tests\Differential;

/**
 * One of the two servers in a flow of requests.
 */
final readonly class Server
{
    public function __construct(
        public string $baseUrl,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $path, array $headers = [], ?string $body = null): Exchange
    {
        return DifferentialTestCase::send($this->baseUrl, $method, $path, $headers, $body);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function json(string $method, string $path, array $data, array $headers = []): Exchange
    {
        return $this->send($method, $path, ['Content-Type' => 'application/json', ...$headers], (string) json_encode($data));
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $headers
     */
    public function form(string $path, array $fields, array $headers = []): Exchange
    {
        return $this->send('POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers], http_build_query($fields));
    }
}
