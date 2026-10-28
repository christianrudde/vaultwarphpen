<?php

namespace App\Notifications;

/**
 * How requests hand their WebSocket messages to the notifications daemon,
 * which sends them to the open hub connections.
 */
interface Channel
{
    /**
     * WebSocketUsers::send_update for each user.
     *
     * @param  list<string>  $userIds
     */
    public function toUsers(array $userIds, string $message): void;

    /**
     * AnonymousWebSocketSubscriptions::send_update.
     */
    public function toAnonymous(string $token, string $message): void;
}
