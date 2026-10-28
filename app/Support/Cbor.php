<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A CBOR decoder (RFC 8949) as serde_cbor_2 reads values for webauthn-rs:
 * integers (as int while they fit), byte strings (CborBytes), text, arrays,
 * maps (CborMap, keys of any type; a repeated key keeps the last value),
 * tags (CborTag), booleans, null, undefined (null) and floats. Indefinite
 * lengths are allowed.
 */
final class Cbor
{
    private const int MAX_DEPTH = 128;

    private function __construct(
        private readonly string $data,
        private int $offset,
    ) {}

    /**
     * One item and the number of bytes it took.
     *
     * @return array{mixed, int}
     *
     * @throws InvalidArgumentException When the data is not well-formed
     */
    public static function decodeFirst(string $data): array
    {
        $decoder = new self($data, 0);
        $value = $decoder->item(0);

        return [$value, $decoder->offset];
    }

    /**
     * Exactly one item.
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $data): mixed
    {
        [$value, $length] = self::decodeFirst($data);
        if ($length !== strlen($data)) {
            throw new InvalidArgumentException('Trailing data after the CBOR item');
        }

        return $value;
    }

    private function item(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('CBOR nested too deeply');
        }
        $initial = $this->byte();
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                24 => $this->simple($this->byte()),
                25 => self::half($this->take(2)),
                26 => self::float('G', $this->take(4)),
                27 => self::float('E', $this->take(8)),
                default => $info < 20 ? throw new InvalidArgumentException('Unassigned simple value') : throw new InvalidArgumentException('Unexpected break'),
            };
        }

        if ($info === 31) {
            return match ($major) {
                2, 3 => $this->indefiniteString($major),
                4 => $this->indefiniteArray($depth),
                5 => $this->indefiniteMap($depth),
                default => throw new InvalidArgumentException('Indefinite length not allowed here'),
            };
        }

        $argument = $this->argument($info);

        return match ($major) {
            0 => $argument,
            1 => -1 - $argument,
            2 => new CborBytes($this->take($this->length($argument))),
            3 => $this->text($this->take($this->length($argument))),
            4 => $this->array($this->length($argument), $depth),
            5 => $this->map($this->length($argument), $depth),
            6 => new CborTag(is_int($argument) ? $argument : throw new InvalidArgumentException('Tag out of range'), $this->item($depth + 1)),
            default => throw new InvalidArgumentException('Unknown major type'),
        };
    }

    /**
     * The argument of a head: an int, or a float when beyond PHP_INT_MAX.
     */
    private function argument(int $info): int|float
    {
        if ($info < 24) {
            return $info;
        }

        return match ($info) {
            24 => $this->byte(),
            25 => self::bigEndian($this->take(2)),
            26 => self::bigEndian($this->take(4)),
            27 => self::uint64($this->take(8)),
            default => throw new InvalidArgumentException('Reserved additional information'),
        };
    }

    private static function uint64(string $bytes): int|float
    {
        // Beyond PHP_INT_MAX as a float, which WebAuthn never needs exactly.
        return ord($bytes[0]) < 0x80 ? self::bigEndian($bytes)
            : self::bigEndian(substr($bytes, 0, 4)) * 4294967296.0 + self::bigEndian(substr($bytes, 4));
    }

    private static function bigEndian(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }

    private function length(int|float $argument): int
    {
        if (! is_int($argument) || $argument > strlen($this->data)) {
            throw new InvalidArgumentException('Length beyond the data');
        }

        return $argument;
    }

    /**
     * @return list<mixed>
     */
    private function array(int $count, int $depth): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->item($depth + 1);
        }

        return $items;
    }

    private function map(int $count, int $depth): CborMap
    {
        $map = new CborMap;
        for ($i = 0; $i < $count; $i++) {
            $key = $this->item($depth + 1);
            $map->set($key, $this->item($depth + 1));
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private function indefiniteArray(int $depth): array
    {
        $items = [];
        while (! $this->atBreak()) {
            $items[] = $this->item($depth + 1);
        }

        return $items;
    }

    private function indefiniteMap(int $depth): CborMap
    {
        $map = new CborMap;
        while (! $this->atBreak()) {
            $key = $this->item($depth + 1);
            $map->set($key, $this->item($depth + 1));
        }

        return $map;
    }

    private function indefiniteString(int $major): CborBytes|string
    {
        $value = '';
        while (! $this->atBreak()) {
            $initial = $this->byte();
            if ($initial >> 5 !== $major || ($initial & 0x1F) === 31) {
                throw new InvalidArgumentException('Bad chunk in an indefinite string');
            }
            $value .= $this->take($this->length($this->argument($initial & 0x1F)));
        }

        return $major === 2 ? new CborBytes($value) : $this->text($value);
    }

    private function atBreak(): bool
    {
        if ($this->offset >= strlen($this->data)) {
            throw new InvalidArgumentException('Unexpected end of CBOR data');
        }
        if (ord($this->data[$this->offset]) === 0xFF) {
            $this->offset++;

            return true;
        }

        return false;
    }

    private function text(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : throw new InvalidArgumentException('Invalid UTF-8 in a text string');
    }

    private function simple(int $value): never
    {
        throw new InvalidArgumentException("Unassigned simple value {$value}");
    }

    private static function float(string $format, string $bytes): float
    {
        $value = unpack($format, $bytes)[1] ?? null;

        return is_float($value) ? $value : throw new InvalidArgumentException('Bad float');
    }

    private static function half(string $bytes): float
    {
        $half = (ord($bytes[0]) << 8) | ord($bytes[1]);
        $exponent = ($half >> 10) & 0x1F;
        $mantissa = $half & 0x3FF;
        $value = match (true) {
            $exponent === 0 => $mantissa * 2 ** -24,
            $exponent === 31 => $mantissa === 0 ? INF : NAN,
            default => ($mantissa + 1024) * 2 ** ($exponent - 25),
        };

        return ($half & 0x8000) !== 0 ? -$value : $value;
    }

    private function byte(): int
    {
        return ord($this->take(1));
    }

    private function take(int $length): string
    {
        if ($this->offset + $length > strlen($this->data)) {
            throw new InvalidArgumentException('Unexpected end of CBOR data');
        }
        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }
}
