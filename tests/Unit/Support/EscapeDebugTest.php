<?php

namespace Tests\Unit\Support;

use App\Support\EscapeDebug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * str::escape_debug and <str as Debug>::fmt as core implements them in Rust
 * 1.99: char::escape_debug_ext with both quotes and the first grapheme
 * extender escaped, or in double quotes with every grapheme extender escaped.
 */
class EscapeDebugTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function examples(): iterable
    {
        yield 'plain' => ['kim@example.com', 'kim@example.com', '"kim@example.com"'];
        yield 'quotes' => ["o'neil \"x\"", "o\\'neil \\\"x\\\"", "\"o'neil \\\"x\\\"\""];
        yield 'escapes' => ["a\0b\tc\rd\ne\\f", 'a\0b\tc\rd\ne\\\\f', '"a\0b\tc\rd\ne\\\\f"'];
        yield 'controls' => ["\x01\x7f\u{85}", '\u{1}\u{7f}\u{85}', '"\u{1}\u{7f}\u{85}"'];
        yield 'unicode stays' => ['jürgen@exämple.de ❤', 'jürgen@exämple.de ❤', '"jürgen@exämple.de ❤"'];
        yield 'separators' => ["a\u{a0}b\u{2028}c", 'a\u{a0}b\u{2028}c', '"a\u{a0}b\u{2028}c"'];
        yield 'format' => ["a\u{200b}b", 'a\u{200b}b', '"a\u{200b}b"'];
        yield 'private use and unassigned' => ["\u{e000}\u{378}", '\u{e000}\u{378}', '"\u{e000}\u{378}"'];
        yield 'default ignorable' => ["❤\u{fe0f}\u{3164}", '❤\u{fe0f}\u{3164}', '"❤\u{fe0f}\u{3164}"'];
        yield 'combining marks' => ["\u{301}a\u{301}", "\\u{301}a\u{301}", '"\u{301}a\u{301}"'];
        yield 'other grapheme extenders' => ["\u{9be}a\u{9be}", "\\u{9be}a\u{9be}", '"\u{9be}a\u{9be}"'];
        yield 'halfwidth sound marks' => ["\u{ff9e}\u{ff9f}", "\u{ff9e}\u{ff9f}", "\"\u{ff9e}\u{ff9f}\""];
    }

    #[DataProvider('examples')]
    public function test_escaping(string $value, string $escaped, string $debug): void
    {
        $this->assertSame($escaped, EscapeDebug::of($value));
        $this->assertSame($debug, EscapeDebug::debug($value));
    }
}
