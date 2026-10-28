<?php

namespace App\Support;

/**
 * A CBOR map, whose keys may be of any type. A text key is a PHP string,
 * a byte string key a CborBytes; a repeated key keeps the last value.
 */
final class CborMap
{
    /**
     * @var list<array{mixed, mixed}>
     */
    private array $entries = [];

    public function set(mixed $key, mixed $value): void
    {
        foreach ($this->entries as $index => [$existing]) {
            if (self::same($existing, $key)) {
                $this->entries[$index] = [$key, $value];

                return;
            }
        }
        $this->entries[] = [$key, $value];
    }

    public function has(mixed $key): bool
    {
        foreach ($this->entries as [$existing]) {
            if (self::same($existing, $key)) {
                return true;
            }
        }

        return false;
    }

    public function get(mixed $key): mixed
    {
        foreach ($this->entries as [$existing, $value]) {
            if (self::same($existing, $key)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return $a instanceof CborBytes && $b instanceof CborBytes ? $a->bytes === $b->bytes : $a === $b;
    }
}
