<?php

namespace App\Support;

use Uri\WhatWg\Url;

/**
 * A response of HttpClient whose body has not been read yet. Reading stops
 * at the deadline of the request, like reqwest's timeout.
 */
final class HttpResponse
{
    private ?\InflateContext $inflate;

    /**
     * Whether the body ended where its response said it would.
     */
    private bool $complete = false;

    /**
     * Of a chunked body: what is left of the current chunk, 0 before a size
     * line and -1 before the line break that ends a chunk.
     */
    private int $chunkLeft = 0;

    /**
     * @param  array<string, string>  $headers  The last value of each header, by lower case name
     * @param  resource  $stream
     * @param  list<string>  $cookies  The Set-Cookie headers
     * @param  string  $buffer  What was received after the head
     * @param  int|null  $length  The length of the body, null when it ends with the connection or is chunked
     */
    public function __construct(
        public readonly Url $url,
        public readonly int $status,
        public readonly array $headers,
        private $stream,
        private readonly float $deadline,
        public readonly array $cookies = [],
        private string $buffer = '',
        private ?int $length = null,
        private readonly bool $chunked = false,
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
        while (strlen($body) < $limit) {
            $chunk = $this->next();
            if ($chunk === null) {
                break;
            }
            if ($this->inflate !== null) {
                $chunk = @inflate_add($this->inflate, $chunk, ZLIB_SYNC_FLUSH);
                if ($chunk === false) {
                    $this->complete = false;
                    break;
                }
            }
            $body .= $chunk;
        }
        $this->close();

        return substr($body, 0, $limit);
    }

    /**
     * Whether the body was read to its end, not cut off by an error or the deadline.
     */
    public function complete(): bool
    {
        return $this->complete;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * What arrives next before the deadline: null once the connection has
     * closed or failed, false when the time is up.
     *
     * @param  resource  $stream
     */
    public static function receive($stream, float $deadline): string|false|null
    {
        while (true) {
            $data = @fread($stream, 8192);
            if ($data === false) {
                return null;
            }
            if ($data !== '') {
                return $data;
            }
            if (feof($stream)) {
                return null;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            $read = [$stream];
            $write = $except = null;
            if (@stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000)) === false) {
                return null;
            }
        }
    }

    /**
     * The next piece of the body as it was sent (still compressed), or null
     * at its end and when reading fails.
     */
    private function next(): ?string
    {
        while (true) {
            if ($this->chunked) {
                $piece = $this->dechunk();
                if ($piece === null || $piece !== '') {
                    return $piece;
                }
            } elseif ($this->length === 0) {
                $this->complete = true;

                return null;
            } elseif ($this->buffer !== '') {
                $piece = $this->length === null ? $this->buffer : substr($this->buffer, 0, $this->length);
                $this->buffer = substr($this->buffer, strlen($piece));
                if ($this->length !== null) {
                    $this->length -= strlen($piece);
                }

                return $piece;
            }

            $received = self::receive($this->stream, $this->deadline);
            if (! is_string($received)) {
                // Without a length or chunks, the body ends with the connection.
                $this->complete = $received === null && ! $this->chunked && $this->length === null;

                return null;
            }
            $this->buffer .= $received;
        }
    }

    /**
     * The chunked transfer coding: the next data of the buffer, '' when more
     * must be received first, null after the last chunk or a malformed one.
     */
    private function dechunk(): ?string
    {
        while (true) {
            if ($this->chunkLeft > 0) {
                if ($this->buffer === '') {
                    return '';
                }
                $piece = substr($this->buffer, 0, $this->chunkLeft);
                $this->buffer = substr($this->buffer, strlen($piece));
                $this->chunkLeft -= strlen($piece);
                if ($this->chunkLeft === 0) {
                    $this->chunkLeft = -1;
                }

                return $piece;
            }
            if ($this->chunkLeft === -1) {
                if (strlen($this->buffer) < 2) {
                    return '';
                }
                if (substr($this->buffer, 0, 2) !== "\r\n") {
                    return null;
                }
                $this->buffer = substr($this->buffer, 2);
                $this->chunkLeft = 0;

                continue;
            }

            $end = strpos($this->buffer, "\n");
            if ($end === false) {
                return strlen($this->buffer) > 1024 ? null : '';
            }
            $size = trim(explode(';', substr($this->buffer, 0, $end), 2)[0]);
            $this->buffer = substr($this->buffer, $end + 1);
            if (preg_match('/^[0-9a-fA-F]{1,15}$/D', $size) !== 1) {
                return null;
            }
            $this->chunkLeft = (int) hexdec($size);
            if ($this->chunkLeft === 0) {
                // The trailer that may follow is of no interest.
                $this->complete = true;

                return null;
            }
        }
    }
}
