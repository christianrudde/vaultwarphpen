<?php

namespace Tests\Unit\Notifications;

use App\Notifications\SignalR;
use App\Support\MessagePack;
use App\Support\UpdateType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignalRTest extends TestCase
{
    public function test_an_update_is_an_invocation_of_receive_message_behind_its_length(): void
    {
        $date = new DateTimeImmutable('1970-01-01T00:00:01.5Z');
        $message = SignalR::update(['UserId' => 'u', 'Date' => SignalR::date($date)], UpdateType::SyncSettings, null);

        $body = '9501'.'80'.'c0'.'ae'.bin2hex('ReceiveMessage').'91'.'83'
            .'a9'.bin2hex('ContextId').'c0'
            .'a4'.bin2hex('Type').'0a'
            .'a7'.bin2hex('Payload').'82'.'a6'.bin2hex('UserId').'a175'.'a4'.bin2hex('Date')
            // Nanoseconds in the upper 30 bits, seconds in the lower 34.
            .'d7ff'.'7735940000000001';
        $this->assertSame(sprintf('%02x', strlen($body) / 2).$body, bin2hex($message));
    }

    public function test_the_anonymous_update_names_the_user_and_keeps_upstreams_spelling(): void
    {
        $message = SignalR::anonymousUpdate(['Id' => 'r', 'UserId' => 'u'], UpdateType::AuthRequestResponse, 'u');

        $this->assertSame(
            MessagePack::encode([1, new \stdClass, null, 'AuthRequestResponseRecieved', [['Type' => 16, 'Payload' => ['Id' => 'r', 'UserId' => 'u'], 'UserId' => 'u']]]),
            substr($message, 1),
        );
    }

    public function test_long_messages_take_more_length_bytes(): void
    {
        $message = SignalR::update(['Id' => str_repeat('x', 200)], UpdateType::SyncCiphers, null);
        $length = strlen($message) - 2;

        $this->assertSame([($length & 0x7F) | 0x80, $length >> 7], [ord($message[0]), ord($message[1])]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function initialMessages(): iterable
    {
        yield 'with record separator' => ['{"protocol":"messagepack","version":1}'."\x1e", true];
        yield 'without' => ['{"protocol":"messagepack","version":1}', true];
        yield 'extra fields' => ['{"version":1,"protocol":"messagepack","extra":true}', true];
        yield 'as a sequence' => ['["messagepack",1]', true];
        yield 'too long a sequence' => ['["messagepack",1,2]', false];
        yield 'json protocol' => ['{"protocol":"json","version":1}'."\x1e", false];
        yield 'other version' => ['{"protocol":"messagepack","version":2}', false];
        yield 'float version' => ['{"protocol":"messagepack","version":1.0}', false];
        yield 'not json' => ['hello', false];
    }

    #[DataProvider('initialMessages')]
    public function test_the_handshake_is_recognized_like_serde_reads_it(string $message, bool $initial): void
    {
        $this->assertSame($initial, SignalR::isInitialMessage($message));
    }
}
