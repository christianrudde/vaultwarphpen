<?php

namespace Tests\Unit\Icons;

use App\Icons\IconService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * get_icon_priority, parse_sizes and get_icon_type of src/api/icons.rs.
 */
class IconHeuristicsTest extends TestCase
{
    public function test_priorities(): void
    {
        $this->assertSame(1, IconService::priority('https://example.com/a.ico', '32x32'));
        $this->assertSame(2, IconService::priority('https://example.com/a.ico', '64x64'));
        $this->assertSame(3, IconService::priority('https://example.com/a.ico', '24x24'));
        $this->assertSame(3, IconService::priority('https://example.com/a.ico', '192x192'));
        $this->assertSame(4, IconService::priority('https://example.com/a.ico', '16x16'));
        $this->assertSame(5, IconService::priority('https://example.com/a.ico', '193x193'));
        $this->assertSame(200, IconService::priority('https://example.com/a.png', '32x16'));
        // Without a size, the extension after the last dot of the whole URL.
        $this->assertSame(10, IconService::priority('https://example.com/a.PNG', ''));
        $this->assertSame(20, IconService::priority('https://example.com/a.jpeg', 'any'));
        $this->assertSame(30, IconService::priority('https://example.com/a.png?v=2', ''));
        $this->assertSame(30, IconService::priority('https://example.com/favicon', ''));
        $this->assertSame(30, IconService::priority('data:image/png;base64,AAAA', ''));
    }

    /**
     * @return iterable<string, array{string, array{int, int}}>
     */
    public static function sizes(): iterable
    {
        yield 'square' => ['64x64', [64, 64]];
        yield 'anything between' => ['x128 by 96', [128, 96]];
        yield 'the first pair' => [' 16x16 32x32 ', [16, 16]];
        // Not (0, 0) as the comment on parse_sizes says: the regex splits the digits.
        yield 'one number' => ['32', [3, 2]];
        yield 'one digit' => ['3', [0, 0]];
        yield 'digits split like a regex does' => ['1616', [161, 6]];
        yield 'any' => ['any', [0, 0]];
        yield 'too large for a u16' => ['65536x65536', [0, 0]];
        yield 'leading zeros' => ['032x0032', [32, 32]];
        // \d matches other digits too, but they are no u16.
        yield 'other digits' => ['٣٢x٣٢', [0, 0]];
        yield 'not UTF-8' => ["32\xFFx32", [0, 0]];
    }

    /**
     * @param  array{int, int}  $expected
     */
    #[DataProvider('sizes')]
    public function test_sizes(string $sizes, array $expected): void
    {
        $this->assertSame($expected, IconService::parseSizes($sizes));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function images(): iterable
    {
        yield 'png' => ["\x89PNG\r\n\x1A\nrest", 'png'];
        yield 'ico' => ["\0\0\1\0\1\0", 'x-icon'];
        yield 'ico without images' => ["\0\0\1\0\0\0", null];
        yield 'webp' => ['RIFF0000WEBPVP8 ', 'webp'];
        yield 'other RIFF' => ['RIFF0000WAVEfmt ', null];
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0", 'jpeg'];
        yield 'not jpeg' => ["\xFF\xD8\xFF\x10", null];
        yield 'gif' => ['GIF89a', 'gif'];
        yield 'gif87' => ['GIF87a', 'gif'];
        yield 'bmp' => ["BM\x10\0\0\0\0\0\0\0", 'bmp'];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'svg+xml'];
        yield 'svg after a declaration' => ['<?xml version="1.0"?><SVG/>', 'svg+xml'];
        yield 'svg too far down' => ['<?xml version="1.0"?>'.str_repeat(' ', 1024).'<svg/>', null];
        yield 'not UTF-8 in the first KB' => ["<?xml version=\"1.0\"?>\xFF<svg/>", null];
        yield 'html' => ['<!DOCTYPE html>', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('images')]
    public function test_image_types(string $bytes, ?string $type): void
    {
        $this->assertSame($type, IconService::iconType($bytes));
    }
}
