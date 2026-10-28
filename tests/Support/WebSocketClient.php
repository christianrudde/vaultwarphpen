<?php

namespace Tests\Support;

use RuntimeException;

/**
 * A WebSocket client for the notification hubs: the upgrade request, masked
 * frames out and plain frames in. The answer to the upgrade is kept, also
 * when it is not one.
 */
final class WebSocketClient
{
    public const int TEXT = 0x1;

    public const int BINARY = 0x2;

    public const int CLOSE = 0x8;

    public const int PING = 0x9;

    public const int PONG = 0xA;

    private string $buffer = '';

    private bool $closed = false;

    /**
     * @param  resource  $socket
     * @param  array<string, string>  $headers  Lower-case names, repeated ones joined with ", "
     */
    private function __construct(
        private readonly mixed $socket,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * Send the upgrade request (with any extra or replaced headers; null
     * drops one) and read the answer.
     *
     * @param  array<string, string|null>  $headers
     */
    public static function connect(string $baseUrl, string $path, array $headers = []): self
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = (int) (parse_url($baseUrl, PHP_URL_PORT) ?? 80);
        $prefix = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 5);
        if ($socket === false) {
            throw new RuntimeException("Cannot connect to {$baseUrl}: {$error}");
        }
        stream_set_timeout($socket, 5);

        $request = [
            'Host' => "{$host}:{$port}",
            'Connection' => 'Upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            ...$headers,
        ];
        $head = "GET {$prefix}{$path} HTTP/1.1\r\n";
        foreach (array_filter($request, static fn (?string $value): bool => $value !== null) as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        fwrite($socket, "{$head}\r\n");

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $line = fgets($socket);
            if ($line === false) {
                break;
            }
            $response .= $line;
        }
        $lines = explode("\r\n", trim($response));
        $status = (int) (explode(' ', (string) array_shift($lines))[1] ?? 0);
        $responseHeaders = [];
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = strtolower(trim($name));
            $responseHeaders[$name] = isset($responseHeaders[$name]) ? "{$responseHeaders[$name]}, ".trim($value) : trim($value);
        }

        $body = '';
        if ($status !== 101) {
            $length = $responseHeaders['content-length'] ?? null;
            while (($length === null || strlen($body) < (int) $length) && ! feof($socket)) {
                $chunk = fread($socket, 65_536);
                if ($chunk === false || ($chunk === '' && stream_get_meta_data($socket)['timed_out'])) {
                    break;
                }
                $body .= $chunk;
            }
        }

        $client = new self($socket, $status, $responseHeaders, $body);
        if ($status !== 101) {
            $client->close();
        }

        return $client;
    }

    /**
     * The SignalR handshake of the MessagePack protocol; the hub answers with {}<RS>.
     */
    public function handshake(): void
    {
        $this->send(self::TEXT, '{"protocol":"messagepack","version":1}'."\x1e");
    }

    public function send(int $opcode, string $payload, bool $fin = true, bool $masked = true): void
    {
        $length = strlen($payload);
        $frame = pack('C', ($fin ? 0x80 : 0) | $opcode);
        $maskBit = $masked ? 0x80 : 0;
        $frame .= match (true) {
            $length < 126 => pack('C', $maskBit | $length),
            $length < 65536 => pack('Cn', $maskBit | 126, $length),
            default => pack('CJ', $maskBit | 127, $length),
        };
        if ($masked) {
            $mask = random_bytes(4);
            $frame .= $mask.($length > 0 ? $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length) : '');
        } else {
            $frame .= $payload;
        }
        fwrite($this->socket, $frame);
    }

    /**
     * The next frame within the time, as opcode and payload; null after the
     * time or once the server closed the connection.
     *
     * @return array{int, string}|null
     */
    public function receive(float $timeout = 5.0): ?array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $frame = $this->parseFrame();
            if ($frame !== null) {
                return $frame;
            }
            $left = $deadline - microtime(true);
            if ($this->closed || $left <= 0) {
                return null;
            }
            $read = [$this->socket];
            $write = $except = null;
            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1_000_000)) < 1) {
                continue;
            }
            $data = fread($this->socket, 65_536);
            if ($data === false || ($data === '' && feof($this->socket))) {
                $this->closed = true;

                continue;
            }
            $this->buffer .= $data;
        }
    }

    /**
     * Every frame of a type that arrives within the time, answering pings;
     * with a count, no longer than until that many arrived.
     *
     * @return list<string> Their payloads
     */
    public function collect(int $opcode = self::BINARY, float $timeout = 1.0, ?int $until = null): array
    {
        $payloads = [];
        $deadline = microtime(true) + $timeout;
        while (($until === null || count($payloads) < $until) && ($left = $deadline - microtime(true)) > 0 && ($frame = $this->receive($left)) !== null) {
            if ($frame[0] === self::PING) {
                $this->send(self::PONG, $frame[1]);
            }
            if ($frame[0] === $opcode) {
                $payloads[] = $frame[1];
            }
        }

        return $payloads;
    }

    /**
     * The binary frames that arrive until none came for a while; the first
     * may take longer. Pings are answered.
     *
     * @return list<string> Their payloads
     */
    public function drain(float $first = 1.0, float $quiet = 0.25): array
    {
        $payloads = [];
        $wait = $first;
        while (($frame = $this->receive($wait)) !== null) {
            if ($frame[0] === self::PING) {
                $this->send(self::PONG, $frame[1]);
            } elseif ($frame[0] === self::BINARY) {
                $payloads[] = $frame[1];
                $wait = $quiet;
            }
        }

        return $payloads;
    }

    /**
     * Whether the server ended the connection within the time.
     */
    public function closedWithin(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (! $this->closed && ($left = $deadline - microtime(true)) > 0) {
            $this->receive($left);
        }

        return $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * @return array{int, string}|null
     */
    private function parseFrame(): ?array
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }
        $length = ord($this->buffer[1]) & 0x7F;
        $offset = 2;
        if ($length === 126) {
            $length = strlen($this->buffer) >= 4 ? (unpack('n', $this->buffer, 2)[1] ?? 0) : -1;
            $offset = 4;
        } elseif ($length === 127) {
            $length = strlen($this->buffer) >= 10 ? (unpack('J', $this->buffer, 2)[1] ?? 0) : -1;
            $offset = 10;
        }
        if (! is_int($length) || $length < 0 || strlen($this->buffer) < $offset + $length) {
            return null;
        }
        $frame = [ord($this->buffer[0]) & 0x0F, substr($this->buffer, $offset, $length)];
        $this->buffer = substr($this->buffer, $offset + $length);

        return $frame;
    }
}
