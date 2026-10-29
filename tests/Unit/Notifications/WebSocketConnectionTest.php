<?php

namespace Tests\Unit\Notifications;

use App\Notifications\WebSocketConnection as Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WebSocketConnectionTest extends TestCase
{
    public function test_masked_frames_become_messages(): void
    {
        $connection = self::connection();
        $connection->input = self::frame(Connection::TEXT, 'hello').self::frame(Connection::PING, 'p').substr(self::frame(Connection::BINARY, 'rest'), 0, 3);

        $this->assertSame([[Connection::TEXT, 'hello'], [Connection::PING, 'p']], $connection->readMessages());

        // The incomplete frame waits for the rest.
        $connection->input .= substr(self::frame(Connection::BINARY, 'rest'), 3);
        $this->assertSame([[Connection::BINARY, 'rest']], $connection->readMessages());
    }

    public function test_fragments_are_joined_and_control_frames_may_come_between(): void
    {
        $connection = self::connection();
        $connection->input = self::frame(Connection::BINARY, 'ab', fin: false)
            .self::frame(Connection::PING, '')
            .self::frame(0x0, 'cd', fin: false)
            .self::frame(0x0, 'ef');

        $this->assertSame([[Connection::PING, ''], [Connection::BINARY, 'abcdef']], $connection->readMessages());
    }

    public function test_long_payloads_carry_their_length_in_16_or_64_bits(): void
    {
        $connection = self::connection();
        // Any length may come in 64 bits, as tungstenite reads it.
        $connection->input = self::frame(Connection::BINARY, str_repeat('a', 300))
            ."\x82\xFF".pack('J', 1000)."\x00\x00\x00\x00".str_repeat('b', 1000)
            .self::frame(Connection::BINARY, str_repeat('c', Connection::MAX_FRAME_SIZE));

        $this->assertSame([[Connection::BINARY, str_repeat('a', 300)], [Connection::BINARY, str_repeat('b', 1000)],
            [Connection::BINARY, str_repeat('c', Connection::MAX_FRAME_SIZE)]], $connection->readMessages());
    }

    public function test_a_frame_too_long_ends_the_connection_before_its_payload_arrives(): void
    {
        $connection = self::connection();
        $connection->input = "\x82\xFE".pack('n', Connection::MAX_FRAME_SIZE + 1);

        $this->expectExceptionMessage('Frame too long');
        $connection->readMessages();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protocolErrors(): iterable
    {
        yield 'unmasked frame' => [self::frame(Connection::TEXT, 'x', masked: false), 'Received an unmasked frame from client'];
        yield 'reserved bits' => ["\xC1\x80abcd", 'Reserved bits are non-zero'];
        yield 'fragmented ping' => [self::frame(Connection::PING, '', fin: false), 'Fragmented control frame'];
        yield 'big ping' => [self::frame(Connection::PING, str_repeat('x', 126)), 'Control frame too big'];
        yield 'continuation first' => [self::frame(0x0, 'x'), 'Continue frame but nothing to continue'];
        yield 'new message within a fragmented one' => [self::frame(Connection::TEXT, 'a', fin: false).self::frame(Connection::TEXT, 'b'),
            'While waiting for more fragments received another data frame'];
        yield 'reserved data opcode' => [self::frame(0x3, ''), 'Unknown data frame type 3'];
        yield 'reserved control opcode' => [self::frame(0xB, ''), 'Unknown control frame type 11'];
        yield 'invalid UTF-8' => [self::frame(Connection::TEXT, "\xFF"), 'UTF-8 encoding error'];
        yield 'close with one byte' => [self::frame(Connection::CLOSE, "\x03"), 'Invalid close sequence'];
        yield 'frame over 32 KiB' => [self::frame(Connection::BINARY, str_repeat('x', (32 << 10) + 1)), 'Frame too long'];
        yield 'length over 63 bits' => ["\x82\xFF".pack('J', PHP_INT_MIN), 'Frame too long'];
        yield 'message over 32 KiB' => [self::frame(Connection::BINARY, str_repeat('x', 16 << 10), fin: false)
            .self::frame(0x0, str_repeat('x', 16 << 10), fin: false).self::frame(0x0, 'x'), 'Message too long'];
    }

    #[DataProvider('protocolErrors')]
    public function test_protocol_errors_end_the_connection(string $input, string $error): void
    {
        $connection = self::connection();
        $connection->input = $input;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($error);
        $connection->readMessages();
    }

    public function test_the_server_sends_unmasked_frames_of_one_piece(): void
    {
        $connection = self::connection();
        $connection->send(Connection::BINARY, 'abc');
        $connection->send(Connection::PING, "\x02\x91\x06");
        $connection->send(Connection::BINARY, str_repeat('x', 126));

        $this->assertSame("\x82\x03abc\x89\x03\x02\x91\x06\x82\x7e\x00\x7e".str_repeat('x', 126), $connection->output);
        $this->assertSame(strlen($connection->output), $connection->buffered());
    }

    public function test_what_it_buffers_counts_input_fragments_and_output(): void
    {
        $connection = self::connection();
        $connection->input = self::frame(Connection::BINARY, 'abc', fin: false).'rest';
        $connection->readMessages();
        $connection->send(Connection::BINARY, 'out');

        $this->assertSame(strlen('rest') + strlen('abc') + strlen("\x82\x03out"), $connection->buffered());
    }

    private static function connection(): Connection
    {
        $socket = fopen('php://memory', 'r+');
        if ($socket === false) {
            self::fail('Cannot open a stream.');
        }

        return new Connection(1, $socket, '127.0.0.1', 1234, 0.0);
    }

    private static function frame(int $opcode, string $payload, bool $fin = true, bool $masked = true): string
    {
        $length = strlen($payload);
        $frame = pack('C', ($fin ? 0x80 : 0) | $opcode);
        $maskBit = $masked ? 0x80 : 0;
        $frame .= match (true) {
            $length < 126 => pack('C', $maskBit | $length),
            $length < 65536 => pack('Cn', $maskBit | 126, $length),
            default => pack('CJ', $maskBit | 127, $length),
        };
        if (! $masked) {
            return $frame.$payload;
        }
        $mask = "\x12\x34\x56\x78";

        return $frame.$mask.($length > 0 ? $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length) : '');
    }
}
