<?php

namespace App\Support;

use Uri\WhatWg\Url;

/**
 * A response of HttpClient::get whose body has not been read yet.
 */
final class HttpResponse
{
    private ?\InflateContext $inflate;

    /**
     * @param  array<string, string>  $headers  The last value of each header, by lower case name
     * @param  resource  $stream
     */
    public function __construct(
        public readonly Url $url,
        public readonly int $status,
        public readonly array $headers,
        private $stream,
        private readonly float $deadline,
    ) {
        // Bodies are decompressed like reqwest's gzip and deflate features.
        $this->inflate = match (strtolower(trim($headers['content-encoding'] ?? ''))) {
            'gzip', 'x-gzip' => inflate_init(ZLIB_ENCODING_GZIP) ?: null,
            'deflate' => inflate_init(ZLIB_ENCODING_DEFLATE) ?: null,
            default => null,
        };
    }

    /**
     * stream_to_bytes_limit in src/api/icons.rs: at most the limit of the
     * body. A body that fails or runs out of time ends where it stops.
     */
    public function read(int $limit): string
    {
        $body = '';
        while (strlen($body) < $limit && ! feof($this->stream)) {
            $remaining = $this->deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            stream_set_timeout($this->stream, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000));
            $chunk = @fread($this->stream, 8192);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($this->stream)['timed_out'])) {
                break;
            }
            if ($this->inflate !== null && $chunk !== '') {
                $chunk = @inflate_add($this->inflate, $chunk, ZLIB_SYNC_FLUSH);
                if ($chunk === false) {
                    break;
                }
            }
            $body .= $chunk;
        }
        $this->close();

        return substr($body, 0, $limit);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
