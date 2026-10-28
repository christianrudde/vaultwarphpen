<?php

namespace Tests\Unit\Support;

use App\Support\MessagePack;
use App\Support\MessagePackExt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Support\MessagePackDecoder;

class MessagePackTest extends TestCase
{
    /**
     * Values and their encoding by rmpv's write_value.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function values(): iterable
    {
        yield 'nil' => [null, 'c0'];
        yield 'false' => [false, 'c2'];
        yield 'true' => [true, 'c3'];
        yield 'positive fixint' => [127, '7f'];
        yield 'u8' => [128, 'cc80'];
        yield 'u16' => [256, 'cd0100'];
        yield 'u32' => [65536, 'ce00010000'];
        yield 'u64' => [4294967296, 'cf0000000100000000'];
        yield 'negative fixint' => [-32, 'e0'];
        yield 'i8' => [-33, 'd0df'];
        yield 'i16' => [-129, 'd1ff7f'];
        yield 'i32' => [-32769, 'd2ffff7fff'];
        yield 'i64' => [-2147483649, 'd3ffffffff7fffffff'];
        yield 'fixstr' => ['abc', 'a3616263'];
        yield 'str8' => [str_repeat('a', 32), 'd920'.str_repeat('61', 32)];
        yield 'str16' => [str_repeat('a', 256), 'da0100'.str_repeat('61', 256)];
        yield 'fixarray' => [[1, 'a'], '9201a161'];
        yield 'array16' => [array_fill(0, 16, 0), 'dc0010'.str_repeat('00', 16)];
        yield 'empty map' => [new stdClass, '80'];
        yield 'fixmap in order' => [['b' => 1, 'a' => null], '82a16201a161c0'];
        yield 'fixext8' => [new MessagePackExt(-1, "\x00\x00\x00\x00\x00\x00\x00\x01"), 'd7ff0000000000000001'];
        yield 'ext8' => [new MessagePackExt(5, 'abc'), 'c70305616263'];
    }

    #[DataProvider('values')]
    public function test_encodes_like_rmpv(mixed $value, string $hex): void
    {
        $this->assertSame($hex, bin2hex(MessagePack::encode($value)));
    }

    public function test_the_decoder_of_the_tests_reads_it_back(): void
    {
        $value = [1, -200, 'text', null, true, ['nested' => [1, 2]], new stdClass];

        $this->assertEquals(
            [1, -200, 'text', null, true, (object) ['nested' => [1, 2]], new stdClass],
            MessagePackDecoder::decode(MessagePack::encode($value)),
        );
    }
}
