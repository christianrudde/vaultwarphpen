<?php

namespace Tests\Fakes;

use App\Support\Downloader;
use RuntimeException;

/**
 * Answers downloads from a list of URLs and keeps the requests; an unknown
 * URL gets a 404, a URL listed with null no response at all.
 */
final class DownloaderFake implements Downloader
{
    /**
     * @var array<string, array{int, string}|null>
     */
    public array $responses = [];

    /**
     * @var list<array{url: string, headers: array<string, string>}>
     */
    public array $requests = [];

    public function download(string $url, array $headers, string $file): int
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        if (array_key_exists($url, $this->responses) && $this->responses[$url] === null) {
            throw new RuntimeException("No response from {$url}: Connection refused");
        }
        [$status, $body] = $this->responses[$url] ?? [404, ''];
        file_put_contents($file, $body);

        return $status;
    }
}
