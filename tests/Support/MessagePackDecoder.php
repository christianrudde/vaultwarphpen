<?php

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use stdClass;

/**
 * Reads the SignalR messages of the hubs: each a MessagePack value behind
 * its length in seven-bit groups. Maps become objects, so that they stay
 * apart from lists in JSON; timestamps become RFC 3339 dates.
 */
final class MessagePackDecoder
{
    private int $offset = 0;

    private function __construct(
        private readonly string $data,
    ) {}

    /**
     * @return list<mixed>
     */
    public static function signalR(string $frame): array
    {
        $messages = [];
        $offset = 0;
        while ($offset < strlen($frame)) {
            $length = 0;
            $shift = 0;
            do {
                $byte = ord($frame[$offset++] ?? throw new RuntimeException('Truncated length'));
                $length |= ($byte & 0x7F) << $shift;
                $shift += 7;
            } while (($byte & 0x80) !== 0);

            $decoder = new self(substr($frame, $offset, $length));
            $messages[] = $decoder->value();
            if ($decoder->offset !== $length) {
                throw new RuntimeException('The length does not match the message.');
            }
            $offset += $length;
        }

        return $messages;
    }

    public static function decode(string $data): mixed
    {
        return (new self($data))->value();
    }

    private function value(): mixed
    {
        $marker = $this->byte();

        return match (true) {
            $marker <= 0x7F => $marker,
            $marker >= 0xE0 => $marker - 0x100,
            ($marker & 0xF0) === 0x80 => $this->map($marker & 0x0F),
            ($marker & 0xF0) === 0x90 => $this->array($marker & 0x0F),
            ($marker & 0xE0) === 0xA0 => $this->take($marker & 0x1F),
            $marker === 0xC0 => null,
            $marker === 0xC2 => false,
            $marker === 0xC3 => true,
            $marker === 0xCC => $this->byte(),
            $marker === 0xCD => $this->unpack('n', 2),
            $marker === 0xCE => $this->unpack('N', 4),
            $marker === 0xCF => $this->unpack('J', 8),
            $marker === 0xD0 => $this->signed('c', 1),
            $marker === 0xD1 => $this->signed('n', 2, 16),
            $marker === 0xD2 => $this->signed('N', 4, 32),
            $marker === 0xD3 => $this->unpack('J', 8),
            $marker === 0xD9 => $this->take($this->byte()),
            $marker === 0xDA => $this->take($this->unpack('n', 2)),
            $marker === 0xDB => $this->take($this->unpack('N', 4)),
            $marker === 0xC4 => ['bin' => bin2hex($this->take($this->byte()))],
            $marker === 0xDC => $this->array($this->unpack('n', 2)),
            $marker === 0xDD => $this->array($this->unpack('N', 4)),
            $marker === 0xDE => $this->map($this->unpack('n', 2)),
            $marker === 0xDF => $this->map($this->unpack('N', 4)),
            $marker >= 0xD4 => $this->ext(1 << ($marker - 0xD4)),
            $marker === 0xC7 => $this->ext($this->byte()),
            default => throw new RuntimeException(sprintf('Unsupported MessagePack marker 0x%02x', $marker)),
        };
    }

    private function ext(int $length): mixed
    {
        $type = $this->signed('c', 1);
        if ($type === -1 && $length === 8) {
            $value = $this->unpack('J', 8);
            $seconds = $value & 0x3FFFFFFFF;
            $nanos = ($value >> 34) & 0x3FFFFFFF;
            $date = (new DateTimeImmutable("@{$seconds}"))->setTimezone(new DateTimeZone('UTC'));

            return $date->format('Y-m-d\TH:i:s').sprintf('.%09dZ', $nanos);
        }

        return ['ext' => $type, 'data' => bin2hex($this->take($length))];
    }

    /**
     * @return list<mixed>
     */
    private function array(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->value();
        }

        return $items;
    }

    private function map(int $count): stdClass
    {
        $map = new stdClass;
        for ($i = 0; $i < $count; $i++) {
            $key = $this->value();
            $map->{is_scalar($key) ? (string) $key : json_encode($key)} = $this->value();
        }

        return $map;
    }

    private function byte(): int
    {
        return ord($this->take(1));
    }

    private function unpack(string $format, int $length): int
    {
        $value = unpack($format, $this->take($length))[1] ?? 0;

        return is_int($value) ? $value : 0;
    }

    private function signed(string $format, int $length, int $bits = 8): int
    {
        $value = $this->unpack($format, $length);

        return $bits < 64 && $value >= 1 << ($bits - 1) ? $value - (1 << $bits) : $value;
    }

    private function take(int $length): string
    {
        if ($this->offset + $length > strlen($this->data)) {
            throw new RuntimeException('Truncated MessagePack value');
        }
        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }
}
