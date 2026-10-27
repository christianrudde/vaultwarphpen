<?php

namespace App\Config;

/**
 * The Rust types of Vaultwarden's settings, with the parsing rules of
 * `str::parse` (environment) and serde (config.json).
 */
enum SettingType: string
{
    case String = 'String';
    case Pass = 'Pass';
    case Bool = 'bool';
    case U8 = 'u8';
    case U16 = 'u16';
    case U32 = 'u32';
    case I32 = 'i32';
    case I64 = 'i64';
    case Usize = 'usize';

    private const array TRUE_VALUES = ['true', 't', 'yes', 'y', '1'];

    private const array FALSE_VALUES = ['false', 'f', 'no', 'n', '0'];

    /**
     * Parse an environment value like get_env / get_env_bool; null when invalid.
     */
    public function parse(string $value): string|int|bool|null
    {
        return match ($this) {
            self::String, self::Pass => $value,
            self::Bool => match (true) {
                in_array(strtolower($value), self::TRUE_VALUES, true) => true,
                in_array(strtolower($value), self::FALSE_VALUES, true) => false,
                default => null,
            },
            default => $this->parseInteger($value),
        };
    }

    /**
     * Whether serde would deserialize a config.json value into this type. JSON null means unset.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String, self::Pass => is_string($value),
            self::Bool => is_bool($value),
            default => is_int($value) && $value >= $this->range()[0] && $value <= $this->range()[1],
        };
    }

    public function isInteger(): bool
    {
        return ! in_array($this, [self::String, self::Pass, self::Bool], true);
    }

    /**
     * Rust's integer `from_str`: an optional sign (`-` only for signed types),
     * ASCII digits only, and the value must fit the type.
     */
    private function parseInteger(string $value): ?int
    {
        [$min, $max] = $this->range();
        if (! preg_match($min < 0 ? '/^[+-]?[0-9]+$/D' : '/^\+?[0-9]+$/D', $value)) {
            return null;
        }

        $negative = $value[0] === '-';
        $digits = ltrim(ltrim($value, '+-'), '0');
        $limit = $negative ? substr((string) $min, 1) : (string) $max;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }

        if ($digits === '') {
            return 0;
        }

        // The only value whose magnitude exceeds PHP_INT_MAX is i64::MIN itself.
        return $negative ? ($digits === $limit ? $min : -(int) $digits) : (int) $digits;
    }

    /**
     * @return array{int, int}
     */
    private function range(): array
    {
        return match ($this) {
            self::U8 => [0, 255],
            self::U16 => [0, 65_535],
            self::U32 => [0, 4_294_967_295],
            self::I32 => [-2_147_483_648, 2_147_483_647],
            // u64 values above PHP_INT_MAX cannot be represented; no setting needs them.
            self::I64, self::Usize => [$this === self::I64 ? PHP_INT_MIN : 0, PHP_INT_MAX],
            default => [0, 0],
        };
    }
}
