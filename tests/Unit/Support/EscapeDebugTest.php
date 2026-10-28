<?php

namespace Tests\Unit\Support;

use App\Support\EscapeDebug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * str::escape_debug as core implements it: char::escape_debug_ext with both
 * quotes escaped, and grapheme extenders only at the start.
 */
class EscapeDebugTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'plain' => ['kim@example.com', 'kim@example.com'];
        yield 'quotes' => ["o'neil \"x\"", "o\\'neil \\\"x\\\""];
        yield 'escapes' => ["a\0b\tc\rd\ne\\f", 'a\0b\tc\rd\ne\\\\f'];
        yield 'controls' => ["\x01\x7f", '\u{1}\u{7f}'];
        yield 'unicode stays' => ['jürgen@exämple.de ❤', 'jürgen@exämple.de ❤'];
        yield 'separators' => ["a\u{a0}b\u{2028}c", 'a\u{a0}b\u{2028}c'];
        yield 'format' => ["a\u{200b}b", 'a\u{200b}b'];
        yield 'leading combining mark' => ["\u{301}a\u{301}", "\\u{301}a\u{301}"];
    }

    #[DataProvider('examples')]
    public function test_escaping(string $value, string $expected): void
    {
        $this->assertSame($expected, EscapeDebug::of($value));
    }
}
