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

    /**
     * A multipart/form-data POST. Each part is [name, contents] or, for a
     * file, [name, contents, file name].
     *
     * @param  list<array{0: string, 1: string, 2?: string}>  $parts
     * @param  array<string, string>  $headers
     */
    public function multipart(string $path, array $parts, array $headers = []): Exchange
    {
        $boundary = 'differential-boundary';
        $body = '';
        foreach ($parts as $part) {
            $disposition = "form-data; name=\"{$part[0]}\"".(isset($part[2]) ? "; filename=\"{$part[2]}\"" : '');
            $type = isset($part[2]) ? "\r\nContent-Type: application/octet-stream" : '';
            $body .= "--{$boundary}\r\nContent-Disposition: {$disposition}{$type}\r\n\r\n{$part[1]}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return $this->send('POST', $path, ['Content-Type' => "multipart/form-data; boundary={$boundary}", ...$headers], $body);
    }
}
