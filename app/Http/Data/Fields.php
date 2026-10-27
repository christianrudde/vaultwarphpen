<?php

namespace App\Http\Data;

use App\Http\HttpStatus;
use stdClass;

/**
 * Reads a JSON object the way serde deserializes a Rust struct: fields by
 * name or alias (both at once is a duplicate), unknown fields ignored,
 * missing or null Options are None, and any mismatch is a 422.
 */
final readonly class Fields
{
    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(
        private array $values,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function of(mixed $value): self
    {
        if (! $value instanceof stdClass) {
            throw new HttpStatus(422, 'Expected a JSON object.');
        }

        $values = [];
        foreach (get_object_vars($value) as $name => $field) {
            $values[(string) $name] = $field;
        }

        return new self($values);
    }

    /**
     * @throws HttpStatus
     */
    public function string(string $name, string ...$aliases): string
    {
        return $this->optionalString($name, ...$aliases) ?? throw new HttpStatus(422, "Missing field `{$name}`.");
    }

    /**
     * @throws HttpStatus
     */
    public function optionalString(string $name, string ...$aliases): ?string
    {
        $value = $this->get($name, array_values($aliases));

        return $value === null || is_string($value) ? $value : throw new HttpStatus(422, "Field `{$name}` must be a string.");
    }

    /**
     * An i32.
     *
     * @throws HttpStatus
     */
    public function int(string $name, string ...$aliases): int
    {
        return $this->optionalInt($name, ...$aliases) ?? throw new HttpStatus(422, "Missing field `{$name}`.");
    }

    /**
     * @throws HttpStatus
     */
    public function optionalInt(string $name, string ...$aliases): ?int
    {
        $value = $this->get($name, array_values($aliases));
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < -2_147_483_648 || $value > 2_147_483_647) {
            throw new HttpStatus(422, "Field `{$name}` must be an i32.");
        }

        return $value;
    }

    /**
     * @throws HttpStatus
     */
    public function bool(string $name, string ...$aliases): bool
    {
        $value = $this->get($name, array_values($aliases));

        return is_bool($value) ? $value : throw new HttpStatus(422, "Field `{$name}` must be a boolean.");
    }

    /**
     * @throws HttpStatus
     */
    public function optionalBool(string $name, string ...$aliases): ?bool
    {
        $value = $this->get($name, array_values($aliases));

        return $value === null || is_bool($value) ? $value : throw new HttpStatus(422, "Field `{$name}` must be a boolean.");
    }

    /**
     * An Option<serde_json::Value>: any JSON, objects as stdClass; null when missing or null.
     *
     * @throws HttpStatus
     */
    public function value(string $name, string ...$aliases): mixed
    {
        return $this->get($name, array_values($aliases));
    }

    /**
     * A Vec<String> or HashSet<String>.
     *
     * @return list<string>
     *
     * @throws HttpStatus
     */
    public function stringList(string $name, string ...$aliases): array
    {
        $value = $this->get($name, array_values($aliases)) ?? throw new HttpStatus(422, "Missing field `{$name}`.");
        if (! is_array($value) || ! array_is_list($value)) {
            throw new HttpStatus(422, "Field `{$name}` must be a sequence.");
        }

        $strings = [];
        foreach ($value as $item) {
            $strings[] = is_string($item) ? $item : throw new HttpStatus(422, "Field `{$name}` must hold strings.");
        }

        return $strings;
    }

    /**
     * `deserialize_with = deser_opt_nonempty_str`: an empty string counts as none.
     *
     * @throws HttpStatus
     */
    public function optionalNonEmptyString(string $name, string ...$aliases): ?string
    {
        $value = $this->optionalString($name, ...$aliases);

        return $value === '' ? null : $value;
    }

    /**
     * @throws HttpStatus
     */
    public function object(string $name, string ...$aliases): self
    {
        return $this->optionalObject($name, ...$aliases) ?? throw new HttpStatus(422, "Missing field `{$name}`.");
    }

    /**
     * @throws HttpStatus
     */
    public function optionalObject(string $name, string ...$aliases): ?self
    {
        $value = $this->get($name, array_values($aliases));

        return $value === null ? null : self::of($value);
    }

    /**
     * @param  list<string>  $aliases
     *
     * @throws HttpStatus
     */
    private function get(string $name, array $aliases): mixed
    {
        $found = array_values(array_filter(
            [$name, ...$aliases],
            fn (string $key): bool => array_key_exists($key, $this->values),
        ));

        if (count($found) > 1) {
            throw new HttpStatus(422, "Duplicate field `{$name}`.");
        }

        return $found === [] ? null : $this->values[$found[0]];
    }
}
