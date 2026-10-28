<?php

namespace App\Notifications;

use App\Support\MessagePack;
use App\Support\MessagePackExt;
use App\Support\UpdateType;
use DateTimeInterface;
use stdClass;

/**
 * The SignalR messages of src/api/notifications.rs, in the MessagePack hub
 * protocol: what the hubs answer and the updates they send.
 */
final class SignalR
{
    public const string RECORD_SEPARATOR = "\x1e";

    // {, }, <RS>
    public const string INITIAL_RESPONSE = "{}\x1e";

    public const string PING = "\x02\x91\x06";

    /**
     * Whether a text message is the handshake {"protocol":"messagepack","version":1},
     * as serde reads it into InitialMessage: other fields are ignored, the
     * version must be an integer, and a sequence of both values works too.
     */
    public static function isInitialMessage(string $message): bool
    {
        if (str_ends_with($message, self::RECORD_SEPARATOR)) {
            $message = substr($message, 0, -1);
        }
        $value = json_decode($message);
        if (is_array($value) && count($value) === 2) {
            $value = (object) ['protocol' => $value[0], 'version' => $value[1]];
        }

        return $value instanceof stdClass
            && ($value->protocol ?? null) === 'messagepack'
            && ($value->version ?? null) === 1;
    }

    /**
     * create_update: an Invocation of ReceiveMessage
     *
     * @param  array<string, mixed>  $payload
     */
    public static function update(array $payload, UpdateType $type, ?string $actingDeviceId): string
    {
        return self::serialize([
            1, // MessageType.Invocation
            new stdClass, // Headers
            null, // InvocationId
            'ReceiveMessage',
            [[
                'ContextId' => $actingDeviceId,
                'Type' => $type->value,
                'Payload' => $payload,
            ]],
        ]);
    }

    /**
     * create_anonymous_update: the target is misspelled like upstream's.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function anonymousUpdate(array $payload, UpdateType $type, string $userId): string
    {
        return self::serialize([
            1,
            new stdClass,
            null,
            'AuthRequestResponseRecieved',
            [[
                'Type' => $type->value,
                'Payload' => $payload,
                'UserId' => $userId,
            ]],
        ]);
    }

    /**
     * serialize_date: a MessagePack timestamp (extension -1) of 64 bits,
     * nanoseconds above the seconds.
     */
    public static function date(DateTimeInterface $date): MessagePackExt
    {
        $seconds = $date->getTimestamp();
        $nanos = (int) $date->format('u') * 1000;

        return new MessagePackExt(-1, pack('J', ($nanos << 34) | $seconds));
    }

    /**
     * serialize: the message with its length in front, seven bits per byte
     * (BinaryMessageFormat.js).
     */
    private static function serialize(mixed $value): string
    {
        $message = MessagePack::encode($value);

        $size = strlen($message);
        $prefix = '';
        do {
            $part = $size & 0x7F;
            $size >>= 7;
            $prefix .= chr($size > 0 ? $part | 0x80 : $part);
        } while ($size > 0);

        return $prefix.$message;
    }
}
