<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Server;
use App\Notifications\SocketChannel;
use App\Notifications\Subscriptions;
use App\Notifications\WebSocketAccept;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The notifications daemon in this process, its hub accepting every request
 * for one user, so that its limits can be tried with small sizes.
 */
class ServerTest extends TestCase
{
    private const string KEY = 'channel key';

    private Server $server;

    /**
     * @var list<resource>
     */
    private array $sockets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new Server(new Subscriptions, static fn (Request $request): Response => new WebSocketAccept('key', '127.0.0.1', userId: 'user'),
            self::KEY, public_path('index.php'), maxBuffered: 100_000);
        $this->server->listen('127.0.0.1:0', "unix://{$this->dataFolder}/n.sock");
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }

        parent::tearDown();
    }

    public function test_past_the_budget_the_connections_holding_the_most_are_dropped(): void
    {
        [$small, $large, $last] = [$this->hub(), $this->hub(), $this->hub()];

        // 10 kB of a frame, then 60 kB of a message, then 35 kB more: the large one goes.
        fwrite($small, substr(self::frame(str_repeat('s', 30_000)), 0, 10_008));
        fwrite($large, self::frame(str_repeat('l', 30_000), fin: false).substr(self::frame(str_repeat('l', 30_000), opcode: 0x0), 0, 30_000));
        $this->pump();
        fwrite($last, self::frame(str_repeat('t', 20_000), fin: false).substr(self::frame(str_repeat('t', 20_000), opcode: 0x0), 0, 15_000));
        $this->pump();

        $this->assertTrue($this->closed($large));
        $this->assertFalse($this->closed($last));

        // The others are served as before.
        fwrite($small, substr(self::frame(str_repeat('s', 30_000)), 10_008));
        $this->pump();
        $this->assertStringEndsWith("\x82\x7e\x75\x30".str_repeat('s', 30_000), $this->read($small));
    }

    public function test_a_query_with_more_fields_than_max_input_vars_is_served(): void
    {
        // parse_str() warns past max_input_vars, which ended the daemon for everybody.
        $socket = $this->connect("tcp://{$this->server->address()}");
        fwrite($socket, 'GET /notifications/hub?'.str_repeat('a&', ((int) ini_get('max_input_vars')) + 1)."access_token=t HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->pump();

        $this->assertStringStartsWith('HTTP/1.1 101 ', $this->read($socket));
        $this->hub();
    }

    public function test_the_channel_takes_a_limited_number_of_requests_and_only_signed_lines(): void
    {
        $hub = $this->hub();
        $this->read($hub);

        $channel = [];
        for ($i = 0; $i < Server::MAX_CHANNEL_CLIENTS; $i++) {
            $channel[] = $this->connect("unix://{$this->dataFolder}/n.sock");
            $this->pump(1);
        }
        $refused = $this->connect("unix://{$this->dataFolder}/n.sock");
        $this->pump();
        $this->assertTrue($this->closed($refused));

        // A line that is not signed with the key ends that connection; the others still deliver.
        fwrite($channel[0], "unsigned\n".SocketChannel::encode(['users' => ['user'], 'message' => base64_encode('lost')], self::KEY));
        fwrite($channel[1], SocketChannel::encode(['users' => ['user'], 'message' => base64_encode('delivered')], self::KEY));
        $this->pump();

        $this->assertTrue($this->closed($channel[0]));
        $this->assertSame("\x82\x09delivered", $this->read($hub));
    }

    /**
     * A connection to the hub, upgraded and with the first ping read.
     *
     * @return resource
     */
    private function hub(): mixed
    {
        $socket = $this->connect("tcp://{$this->server->address()}");
        fwrite($socket, "GET /notifications/hub HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->pump();
        $this->assertStringStartsWith('HTTP/1.1 101 ', $this->read($socket));

        return $socket;
    }

    /**
     * @return resource
     */
    private function connect(string $address): mixed
    {
        $socket = stream_socket_client($address, $errno, $error, 1);
        $this->assertNotFalse($socket, "Cannot connect to {$address}: {$error}");
        stream_set_blocking($socket, false);
        $this->sockets[] = $socket;

        return $socket;
    }

    /**
     * Let the server serve what came in, a connection to accept or something to read in each round.
     */
    private function pump(int $rounds = 20): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $this->server->runOnce(0.001);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function read(mixed $socket): string
    {
        $data = '';
        while (($chunk = @fread($socket, 65_536)) !== false && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Whether the server ended the connection.
     *
     * @param  resource  $socket
     */
    private function closed(mixed $socket): bool
    {
        $this->read($socket);

        return feof($socket);
    }

    private static function frame(string $payload, int $opcode = 0x2, bool $fin = true): string
    {
        $mask = "\x12\x34\x56\x78";
        $length = strlen($payload);

        return pack('C', ($fin ? 0x80 : 0) | $opcode).($length < 126 ? pack('C', 0x80 | $length) : pack('Cn', 0x80 | 126, $length))
            .$mask.($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length));
    }
}
