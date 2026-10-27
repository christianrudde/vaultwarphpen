<?php

namespace App\Templates;

use InvalidArgumentException;

/**
 * Version requirements as understood by Rust's semver crate, which the
 * "webver" and "vwver" template helpers use: comma separated comparators
 * like ">=2025.1.0, <2025.12.2", "^1.32", "~1.2" or "=1.2.3".
 */
final class VersionRequirement
{
    public static function matches(string $requirement, string $version): bool
    {
        $version = self::parse($version, partial: false)['parts'];

        foreach (explode(',', $requirement) as $comparator) {
            if (! self::comparatorMatches(trim($comparator), $version)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{int, int, int}  $version
     */
    private static function comparatorMatches(string $comparator, array $version): bool
    {
        if ($comparator === '*') {
            return true;
        }

        if (! preg_match('/^(=|>=|<=|>|<|~|\^)?\s*(.+)$/', $comparator, $match)) {
            throw new InvalidArgumentException("Invalid version requirement [{$comparator}].");
        }

        ['parts' => [$major, $minor, $patch], 'given' => $given] = self::parse($match[2], partial: true);
        $lower = [$major, $minor ?? 0, $patch ?? 0];

        // The first version above a partial requirement, e.g. 1.3.0 for "1.2".
        $next = match ($given) {
            1 => [$major + 1, 0, 0],
            2 => [$major, (int) $minor + 1, 0],
            default => [$major, (int) $minor, (int) $patch + 1],
        };

        return match ($match[1]) {
            '=' => self::compare($version, $lower) >= 0 && self::compare($version, $next) < 0,
            '>' => self::compare($version, $next) >= 0,
            '>=' => self::compare($version, $lower) >= 0,
            '<' => self::compare($version, $lower) < 0,
            '<=' => self::compare($version, $next) < 0,
            '~' => self::compare($version, $lower) >= 0 && self::compare($version, $given === 1 ? $next : [$major, (int) $minor + 1, 0]) < 0,
            default => self::compare($version, $lower) >= 0 && self::compare($version, self::caretUpperBound($major, $minor, $patch)) < 0,
        };
    }

    /**
     * ^1.2.3 allows 1.x.y, ^0.2.3 allows 0.2.x, ^0.0.3 allows only 0.0.3.
     *
     * @return array{int, int, int}
     */
    private static function caretUpperBound(int $major, ?int $minor, ?int $patch): array
    {
        return match (true) {
            $major > 0 || $minor === null => [$major + 1, 0, 0],
            $minor > 0 || $patch === null => [0, $minor + 1, 0],
            default => [0, 0, $patch + 1],
        };
    }

    /**
     * @return ($partial is true ? array{parts: array{int, ?int, ?int}, given: int} : array{parts: array{int, int, int}, given: int})
     */
    private static function parse(string $version, bool $partial): array
    {
        $pattern = $partial ? '/^(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?$/' : '/^(\d+)\.(\d+)\.(\d+)$/';

        if (! preg_match($pattern, trim($version), $match)) {
            throw new InvalidArgumentException("Invalid version [{$version}].");
        }

        $parts = [(int) $match[1], null, null];
        $given = 1;
        foreach ([2, 3] as $index) {
            if (isset($match[$index]) && $match[$index] !== '' && $match[$index] !== '*') {
                $parts[$index - 1] = (int) $match[$index];
                $given = $index;
            } else {
                break;
            }
        }

        return ['parts' => $parts, 'given' => $given];
    }

    /**
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a <=> $b;
    }
}
