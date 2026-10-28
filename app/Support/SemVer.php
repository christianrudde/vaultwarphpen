<?php

namespace App\Support;

/**
 * Semantic versions as the semver crate parses and orders them: pre-release
 * versions come before the release, build metadata breaks ties.
 */
final class SemVer
{
    /**
     * @return array{list<int>, list<string>, list<string>}|null Version, pre-release and build identifiers
     */
    public static function parse(string $version): ?array
    {
        $number = '(0|[1-9][0-9]*)';
        $identifiers = '[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*';
        if (! preg_match("/^{$number}\\.{$number}\\.{$number}(?:-({$identifiers}))?(?:\\+({$identifiers}))?$/D", $version, $match)) {
            return null;
        }

        return [
            [(int) $match[1], (int) $match[2], (int) $match[3]],
            ($match[4] ?? '') === '' ? [] : explode('.', $match[4]),
            ($match[5] ?? '') === '' ? [] : explode('.', $match[5]),
        ];
    }

    /**
     * -1, 0 or 1. Both versions must parse.
     *
     * @param  array{list<int>, list<string>, list<string>}  $a
     * @param  array{list<int>, list<string>, list<string>}  $b
     */
    public static function compare(array $a, array $b): int
    {
        $order = $a[0] <=> $b[0];
        if ($order !== 0) {
            return $order;
        }

        // A version without pre-release identifiers is the greater one.
        if ($a[1] !== $b[1]) {
            if ($a[1] === [] || $b[1] === []) {
                return $a[1] === [] ? 1 : -1;
            }
            $order = self::compareIdentifiers($a[1], $b[1]);
            if ($order !== 0) {
                return $order;
            }
        }

        // Without build metadata a version comes first.
        return self::compareIdentifiers($a[2], $b[2]);
    }

    /**
     * Identifier by identifier: numbers by value and before words, words in
     * ASCII order; a shorter list that is a prefix of the other comes first.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function compareIdentifiers(array $a, array $b): int
    {
        foreach ($a as $index => $identifier) {
            if (! isset($b[$index])) {
                return 1;
            }
            $other = $b[$index];
            $numeric = ctype_digit($identifier);
            $otherNumeric = ctype_digit($other);
            $order = match (true) {
                // By value, then a leading zero makes the greater one (build metadata may have them).
                $numeric && $otherNumeric => [strlen(ltrim($identifier, '0')), ltrim($identifier, '0'), strlen($identifier)]
                    <=> [strlen(ltrim($other, '0')), ltrim($other, '0'), strlen($other)],
                $numeric => -1,
                $otherNumeric => 1,
                default => strcmp($identifier, $other) <=> 0,
            };
            if ($order !== 0) {
                return $order;
            }
        }

        return count($a) < count($b) ? -1 : 0;
    }
}
