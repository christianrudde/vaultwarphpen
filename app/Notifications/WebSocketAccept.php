<?php

namespace App\Notifications;

use Symfony\Component\HttpFoundation\Response;

/**
 * The 101 answer of a hub (rocket_ws' MessageStream responder). The
 * notifications daemon sends it and then speaks WebSocket on the connection,
 * subscribed to the user's updates or to the auth request token.
 */
final class WebSocketAccept extends Response
{
    public function __construct(
        string $acceptKey,
        public readonly string $ip,
        public readonly ?string $userId = null,
        public readonly ?string $anonymousToken = null,
    ) {
        parent::__construct('', 101, [
            'Sec-Websocket-Version' => '13',
            'Sec-WebSocket-Accept' => $acceptKey,
            'Connection' => 'upgrade',
            'Upgrade' => 'websocket',
        ]);
    }
}
