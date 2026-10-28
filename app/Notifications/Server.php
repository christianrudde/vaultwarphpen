<?php

namespace App\Notifications;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The notifications daemon: the WebSocket side of src/api/notifications.rs
 * in one process. Requests to it go through the application like any other
 * (the hubs of NotificationsController accept them); accepted connections
 * then receive what requests hand over through the channel. A ping every 15
 * seconds keeps them open, and they are dropped when nothing came back for
 * 30 seconds.
 */
final class Server
{
    // How often a Ping is sent to the client.
    private const float PING_INTERVAL = 15.0;

    // Close the connection if nothing, not even a Pong, was received from the client for this long,
    // like the default ClientTimeoutInterval of ASP.NET Core SignalR.
    private const float CLIENT_TIMEOUT = 30.0;

    // Limits for the HTTP request before the upgrade.
    private const float REQUEST_TIMEOUT = 10.0;

    private const int MAX_REQUEST_HEAD = 16_384;

    private const int MAX_REQUEST_BODY = 1 << 20;

    // A client that reads nothing while this much waits for it is dropped.
    private const int MAX_OUTPUT = 16 << 20;

    private const int MAX_CHANNEL_LINE = 1 << 20;

    /**
     * @var resource|null
     */
    private mixed $listener = null;

    /**
     * @var resource|null
     */
    private mixed $channel = null;

    private ?string $channelPath = null;

    /**
     * @var array<int, WebSocketConnection>
     */
    private array $clients = [];

    /**
     * @var array<int, array{resource, string}> The socket and unread input of each request on the channel
     */
    private array $channelClients = [];

    private int $nextId = 1;

    private bool $stopping = false;

    /**
     * @param  Closure(Request): Response  $dispatch  Handles a request like the web server would
     */
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Closure $dispatch,
        private readonly string $channelKey,
        private readonly string $scriptFilename,
    ) {}

    /**
     * @param  string  $address  Host and port for the clients, such as 127.0.0.1:3012
     * @param  string  $channelAddress  unix:// or tcp:// address for the requests
     */
    public function listen(string $address, string $channelAddress): void
    {
        $listener = @stream_socket_server("tcp://{$address}", $errno, $error);
        if ($listener === false) {
            throw new RuntimeException("Cannot listen on {$address}: {$error}");
        }
        stream_set_blocking($listener, false);
        $this->listener = $listener;

        if (str_starts_with($channelAddress, 'unix://')) {
            $path = substr($channelAddress, strlen('unix://'));
            // sun_path holds 104 bytes on macOS and the BSDs, 108 on Linux.
            if (strlen($path) >= 104) {
                throw new RuntimeException("The socket path {$path} is too long for a Unix socket; set NOTIFICATIONS_SOCKET to a shorter one.");
            }
            if (file_exists($path)) {
                $probe = @stream_socket_client($channelAddress, $errno, $error, 0.5);
                if ($probe !== false) {
                    fclose($probe);

                    throw new RuntimeException("Another notifications daemon listens at {$channelAddress}.");
                }
                @unlink($path);
            }
            $this->channelPath = $path;
        }
        $channel = @stream_socket_server($channelAddress, $errno, $error);
        if ($channel === false) {
            throw new RuntimeException("Cannot listen on {$channelAddress}: {$error}");
        }
        stream_set_blocking($channel, false);
        $this->channel = $channel;
        if ($this->channelPath !== null) {
            // Whoever runs PHP for the web server must reach it; what it accepts is signed anyway.
            @chmod($this->channelPath, 0666);
        }
    }

    /**
     * The address the clients connect to, with the port chosen when it was 0.
     */
    public function address(): string
    {
        return $this->listener === null ? '' : (string) stream_socket_get_name($this->listener, false);
    }

    public function run(): void
    {
        while (! $this->stopping) {
            $this->runOnce(1.0);
        }
        $this->shutdown();
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * One round: wait for sockets (at most until the next ping is due), serve them and send pings.
     */
    public function runOnce(float $maxWait): void
    {
        if ($this->listener === null || $this->channel === null) {
            throw new RuntimeException('The server does not listen yet.');
        }

        $read = ['listener' => $this->listener, 'channel' => $this->channel];
        $write = [];
        foreach ($this->clients as $id => $client) {
            $read["client {$id}"] = $client->socket;
            if ($client->output !== '') {
                $write["client {$id}"] = $client->socket;
            }
        }
        foreach ($this->channelClients as $id => [$socket]) {
            $read["channel {$id}"] = $socket;
        }

        $wait = max(0.0, min($maxWait, $this->untilNextTimer()));
        $except = null;
        // False when a signal interrupts the wait.
        if (@stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1.0) * 1_000_000)) !== false) {
            foreach (array_keys($write) as $key) {
                $client = $this->clients[(int) substr((string) $key, strlen('client '))] ?? null;
                if ($client !== null) {
                    $this->flush($client);
                }
            }
            foreach (array_keys($read) as $key) {
                [$kind, $id] = array_pad(explode(' ', (string) $key, 2), 2, '0');
                match ($kind) {
                    'listener' => $this->acceptClient(),
                    'channel' => $key === 'channel' ? $this->acceptChannelClient() : $this->readChannelClient((int) $id),
                    default => $this->readClient((int) $id),
                };
            }
        }

        $this->tick();
    }

    /**
     * Send a message from the channel to the connections it names.
     *
     * @param  array<mixed>  $message
     */
    public function deliver(array $message): void
    {
        $data = base64_decode(is_string($message['message'] ?? null) ? $message['message'] : '', true);
        if ($data === false) {
            return;
        }

        $connections = [];
        if (is_array($message['users'] ?? null)) {
            foreach (array_filter($message['users'], is_string(...)) as $userId) {
                array_push($connections, ...$this->subscriptions->ofUser($userId));
            }
        }
        if (is_string($message['token'] ?? null)) {
            array_push($connections, ...$this->subscriptions->ofToken($message['token']));
        }

        foreach ($connections as $connection) {
            $connection->send(WebSocketConnection::BINARY, $data);
            $this->flush($connection);
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    private function untilNextTimer(): float
    {
        $now = self::now();
        $next = PHP_FLOAT_MAX;
        foreach ($this->clients as $client) {
            $next = min($next, $client->upgraded ? $client->nextPing : $client->openedAt + self::REQUEST_TIMEOUT);
        }

        return $next - $now;
    }

    private function tick(): void
    {
        $now = self::now();
        foreach ($this->clients as $client) {
            if (! $client->upgraded) {
                if ($now - $client->openedAt > self::REQUEST_TIMEOUT) {
                    $this->close($client);
                }

                continue;
            }
            if ($now < $client->nextPing) {
                continue;
            }
            // The client stopped responding without closing the connection, drop it.
            if ($now - $client->lastReceived > self::CLIENT_TIMEOUT) {
                $this->close($client);

                continue;
            }
            $client->nextPing = max($client->nextPing + self::PING_INTERVAL, $now);
            $client->send(WebSocketConnection::PING, SignalR::PING);
            $this->flush($client);
        }
    }

    private function acceptClient(): void
    {
        if ($this->listener === null) {
            return;
        }
        $socket = @stream_socket_accept($this->listener, 0, $peer);
        if ($socket === false) {
            return;
        }
        stream_set_blocking($socket, false);

        [$address, $port] = self::splitAddress(is_string($peer) ? $peer : '');
        $id = $this->nextId++;
        $this->clients[$id] = new WebSocketConnection($id, $socket, $address, $port, self::now());
    }

    private function readClient(int $id): void
    {
        $client = $this->clients[$id] ?? null;
        if ($client === null) {
            return;
        }
        $data = @fread($client->socket, 65_536);
        if ($data === false || ($data === '' && feof($client->socket))) {
            $this->close($client);

            return;
        }
        $client->input .= $data;

        if ($client->upgraded) {
            $this->readMessages($client);
        } elseif (! $client->closeAfterOutput) {
            $this->readRequest($client);
        }
    }

    /**
     * The HTTP request, once complete: dispatched like any other, then
     * answered and closed, or upgraded when a hub accepted it.
     */
    private function readRequest(WebSocketConnection $client): void
    {
        $end = strpos($client->input, "\r\n\r\n");
        if ($end === false) {
            if (strlen($client->input) > self::MAX_REQUEST_HEAD) {
                $this->plainResponse($client, 431, 'Request Header Fields Too Large');
            }

            return;
        }

        $lines = explode("\r\n", substr($client->input, 0, $end));
        $parts = explode(' ', (string) array_shift($lines));
        if (count($parts) !== 3 || ! preg_match('#^HTTP/1\.[01]$#', $parts[2]) || ! str_starts_with($parts[1], '/')) {
            $this->plainResponse($client, 400, 'Bad Request');

            return;
        }
        [$method, $target, $protocol] = $parts;

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0 || $line[0] === ' ' || $line[0] === "\t") {
                $this->plainResponse($client, 400, 'Bad Request');

                return;
            }
            $headers[] = [substr($line, 0, $colon), trim(substr($line, $colon + 1), " \t")];
        }

        $length = 0;
        foreach ($headers as [$name, $value]) {
            if (strcasecmp($name, 'Content-Length') === 0) {
                $length = ctype_digit($value) ? (int) $value : -1;
            }
        }
        if ($length < 0 || $length > self::MAX_REQUEST_BODY) {
            $this->plainResponse($client, $length < 0 ? 400 : 413, $length < 0 ? 'Bad Request' : 'Payload Too Large');

            return;
        }
        if (strlen($client->input) < $end + 4 + $length) {
            return;
        }
        $body = substr($client->input, $end + 4, $length);
        $client->input = substr($client->input, $end + 4 + $length);

        // Only what lies below the hubs' mount point goes through the application.
        if (preg_match('#^[^?]*/notifications/#', $target) !== 1) {
            $this->plainResponse($client, 404, 'Not Found');

            return;
        }

        $request = $this->request($client, $method, $target, $protocol, $headers, $body);
        try {
            $response = ($this->dispatch)($request);
        } catch (Throwable $exception) {
            report($exception);
            $this->plainResponse($client, 500, 'Internal Server Error');

            return;
        }

        if ($response instanceof WebSocketAccept && $response->getStatusCode() === 101) {
            $this->upgrade($client, $response);

            return;
        }
        if ($response instanceof WebSocketAccept && $response->anonymousToken !== null) {
            $this->subscriptions->release($response->ip);
        }

        ob_start();
        try {
            $response->sendContent();
        } finally {
            $content = (string) ob_get_clean();
        }
        $client->output .= self::responseHead($response, strlen($content)).($method === 'HEAD' ? '' : $content);
        $client->closeAfterOutput = true;
        $this->flush($client);
    }

    private function upgrade(WebSocketConnection $client, WebSocketAccept $response): void
    {
        $client->output .= self::responseHead($response, null);
        $client->upgraded = true;
        $client->ip = $response->ip;
        $client->userId = $response->userId;
        $client->anonymousToken = $response->anonymousToken;
        $this->subscriptions->subscribe($client);

        // Like tokio's interval, the first ping goes out at once.
        $now = self::now();
        $client->lastReceived = $now;
        $client->nextPing = $now + self::PING_INTERVAL;
        $client->send(WebSocketConnection::PING, SignalR::PING);
        $this->flush($client);

        if ($client->input !== '') {
            $this->readMessages($client);
        }
    }

    private function readMessages(WebSocketConnection $client): void
    {
        try {
            $messages = $client->readMessages();
        } catch (RuntimeException $exception) {
            logger()->debug("WebSocket error from {$client->ip}: {$exception->getMessage()}");
            $this->close($client);

            return;
        }

        foreach ($messages as [$opcode, $payload]) {
            // Any message, including a Pong, means the client is still there.
            $client->lastReceived = self::now();

            switch ($opcode) {
                case WebSocketConnection::PING:
                    $client->send(WebSocketConnection::PONG, $payload);
                    break;
                case WebSocketConnection::PONG:
                    break;
                case WebSocketConnection::TEXT:
                    // The initial message with the protocol and version is answered.
                    if (SignalR::isInitialMessage($payload)) {
                        $client->send(WebSocketConnection::BINARY, SignalR::INITIAL_RESPONSE);
                    }
                    break;
                case WebSocketConnection::CLOSE:
                    // Nothing is sent back, the connection just ends.
                    $this->close($client);

                    return;
                default:
                    // Anything else is echoed.
                    $client->send($opcode, $payload);
            }
        }
        $this->flush($client);
    }

    private function flush(WebSocketConnection $client): void
    {
        if (! isset($this->clients[$client->id])) {
            return;
        }
        if ($client->output !== '') {
            $written = @fwrite($client->socket, $client->output);
            if ($written === false) {
                $this->close($client);

                return;
            }
            $client->output = (string) substr($client->output, $written);
        }

        if ($client->output === '' && $client->closeAfterOutput) {
            $this->close($client);
        } elseif (strlen($client->output) > self::MAX_OUTPUT) {
            logger()->error("Error sending WS update to {$client->ip}: the client does not read");
            $this->close($client);
        }
    }

    private function close(WebSocketConnection $client): void
    {
        if (! isset($this->clients[$client->id])) {
            return;
        }
        unset($this->clients[$client->id]);
        if ($client->upgraded) {
            logger()->info("Closing WS connection from {$client->ip}");
            $this->subscriptions->unsubscribe($client);
        }
        @fclose($client->socket);
    }

    private function plainResponse(WebSocketConnection $client, int $status, string $reason): void
    {
        $client->output .= "HTTP/1.1 {$status} {$reason}\r\nContent-Type: text/plain; charset=utf-8\r\n"
            .'Content-Length: '.strlen($reason)."\r\nConnection: close\r\n\r\n{$reason}";
        $client->closeAfterOutput = true;
        $this->flush($client);
    }

    /**
     * @param  list<array{string, string}>  $headers
     */
    private function request(WebSocketConnection $client, string $method, string $target, string $protocol, array $headers, string $body): Request
    {
        [$localAddress, $localPort] = self::splitAddress($this->address());
        $question = strpos($target, '?');
        $queryString = $question === false ? '' : substr($target, $question + 1);
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
            'QUERY_STRING' => $queryString,
            'SERVER_PROTOCOL' => $protocol,
            'REMOTE_ADDR' => $client->peerAddress,
            'REMOTE_PORT' => $client->peerPort,
            'SERVER_ADDR' => $localAddress,
            'SERVER_NAME' => $localAddress,
            'SERVER_PORT' => $localPort,
            // Where the web server would run the application.
            'SCRIPT_FILENAME' => $this->scriptFilename,
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '/index.php',
            'DOCUMENT_ROOT' => dirname($this->scriptFilename),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        $cookies = [];
        foreach ($headers as [$name, $value]) {
            $key = strtoupper(str_replace('-', '_', $name));
            $key = in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_{$key}";
            $server[$key] = isset($server[$key]) ? "{$server[$key]}, {$value}" : $value;
            if (strcasecmp($name, 'Cookie') === 0) {
                foreach (explode(';', $value) as $cookie) {
                    [$cookieName, $cookieValue] = array_pad(explode('=', $cookie, 2), 2, '');
                    $cookies[trim($cookieName)] = urldecode(trim($cookieValue));
                }
            }
        }
        parse_str($queryString, $query);

        return new Request($query, [], [], $cookies, [], $server, $body);
    }

    /**
     * @param  int|null  $length  The length of the body, null for an upgrade
     */
    private static function responseHead(Response $response, ?int $length): string
    {
        $status = $response->getStatusCode();
        $head = sprintf('HTTP/1.1 %d %s', $status, Response::$statusTexts[$status] ?? '')."\r\n";
        // Names in lower case, as hyper writes them.
        foreach ($response->headers->all() as $name => $values) {
            if ($name === 'set-cookie' || ($length !== null && in_array($name, ['connection', 'content-length', 'transfer-encoding'], true))) {
                continue;
            }
            foreach ($values as $value) {
                $head .= "{$name}: {$value}\r\n";
            }
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $head .= "set-cookie: {$cookie}\r\n";
        }
        if ($length !== null) {
            $head .= "content-length: {$length}\r\nconnection: close\r\n";
        }

        return "{$head}\r\n";
    }

    /**
     * @return array{string, int}
     */
    private static function splitAddress(string $address): array
    {
        $colon = strrpos($address, ':');
        if ($colon === false) {
            return [$address, 0];
        }

        return [trim(substr($address, 0, $colon), '[]'), (int) substr($address, $colon + 1)];
    }

    private function shutdown(): void
    {
        foreach ($this->clients as $client) {
            $this->close($client);
        }
        foreach ($this->channelClients as [$socket]) {
            @fclose($socket);
        }
        $this->channelClients = [];
        foreach ([$this->listener, $this->channel] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->listener = $this->channel = null;
        if ($this->channelPath !== null) {
            @unlink($this->channelPath);
        }
    }

    private function acceptChannelClient(): void
    {
        if ($this->channel === null) {
            return;
        }
        $socket = @stream_socket_accept($this->channel, 0);
        if ($socket === false) {
            return;
        }
        stream_set_blocking($socket, false);
        $this->channelClients[$this->nextId++] = [$socket, ''];
    }

    private function readChannelClient(int $id): void
    {
        if (! isset($this->channelClients[$id])) {
            return;
        }
        [$socket, $buffer] = $this->channelClients[$id];
        $data = @fread($socket, 65_536);
        $closed = $data === false || ($data === '' && feof($socket));
        $buffer .= (string) $data;

        while (($newline = strpos($buffer, "\n")) !== false) {
            $message = SocketChannel::decode(substr($buffer, 0, $newline), $this->channelKey);
            $buffer = substr($buffer, $newline + 1);
            if ($message === null) {
                logger()->warning('The notifications daemon received a message that is not signed with its key.');

                continue;
            }
            $this->deliver($message);
        }

        if ($closed || strlen($buffer) > self::MAX_CHANNEL_LINE) {
            @fclose($socket);
            unset($this->channelClients[$id]);
        } else {
            $this->channelClients[$id] = [$socket, $buffer];
        }
    }
}
