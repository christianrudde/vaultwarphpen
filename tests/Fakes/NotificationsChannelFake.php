<?php

namespace Tests\Fakes;

use App\Notifications\Channel;
use Tests\Support\MessagePackDecoder;

/**
 * Keeps what requests hand to the notifications daemon.
 */
final class NotificationsChannelFake implements Channel
{
    /**
     * @var list<array{users: list<string>|null, token: string|null, message: string}>
     */
    public array $sent = [];

    public function toUsers(array $userIds, string $message): void
    {
        $this->sent[] = ['users' => $userIds, 'token' => null, 'message' => $message];
    }

    public function toAnonymous(string $token, string $message): void
    {
        $this->sent[] = ['users' => null, 'token' => $token, 'message' => $message];
    }

    /**
     * The updates sent so far, as the recipients and the decoded argument of
     * the invocation, as JSON: {"to": [...], "target": ..., "update": {...}}.
     *
     * @return list<array{to: list<string>|string, target: mixed, update: mixed}>
     */
    public function updates(): array
    {
        return array_map(static function (array $sent): array {
            $invocation = MessagePackDecoder::signalR($sent['message'])[0] ?? null;
            $invocation = is_array($invocation) ? $invocation : [];
            $arguments = is_array($invocation[4] ?? null) ? $invocation[4] : [];

            return [
                'to' => $sent['users'] ?? (string) $sent['token'],
                'target' => $invocation[3] ?? null,
                'update' => json_decode((string) json_encode($arguments[0] ?? null), true),
            ];
        }, $this->sent);
    }
}
