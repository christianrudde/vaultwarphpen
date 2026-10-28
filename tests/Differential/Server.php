<?php

namespace Tests\Differential;

use RuntimeException;
use Tests\Support\WebSocketClient;

/**
 * One of the two servers in a flow of requests, with the mails it sent, its
 * notification hubs and what it sent to its push relay.
 */
final readonly class Server
{
    public function __construct(
        public string $baseUrl,
        // Where the SMTP sink of the server writes its mails, for servers with mail.
        public ?string $mailDir = null,
        // Where the hubs are: Vaultwarden serves them itself, we from the notifications daemon.
        public ?string $notificationsUrl = null,
        // The label of the server's fake push relay (fake-services.php), for servers with push.
        public ?string $relay = null,
    ) {}

    /**
     * An upgrade request to a notification hub.
     *
     * @param  array<string, string|null>  $headers
     */
    public function hub(string $path, array $headers = []): WebSocketClient
    {
        return WebSocketClient::connect($this->notificationsUrl ?? $this->baseUrl, $path, $headers);
    }

    /**
     * Everything the server's push relay received so far, in order.
     *
     * @return list<mixed>
     */
    public function relayed(): array
    {
        $context = stream_context_create(['ssl' => ['cafile' => (string) getenv('VW_SERVICES_CA')]]);
        $body = file_get_contents('https://'.getenv('VW_SERVICES_HOST')."/relay/{$this->relay}/recorded", false, $context);
        $recorded = json_decode((string) $body);

        return is_array($recorded) ? array_values($recorded) : throw new RuntimeException("The push relay {$this->relay} cannot be read.");
    }

    /**
     * The last mail the server sent to the address.
     */
    public function mail(string $to): CapturedMail
    {
        $files = $this->mailDir === null ? [] : (glob("{$this->mailDir}/*.eml") ?: []);
        rsort($files);
        foreach ($files as $file) {
            $mail = CapturedMail::parse((string) file_get_contents($file));
            if (strcasecmp($mail->to, $to) === 0) {
                return $mail;
            }
        }

        throw new RuntimeException("No mail to {$to} in {$this->mailDir}.");
    }

    /**
     * The token of the link in the server's last mail to the address.
     */
    public function mailedToken(string $to): string
    {
        $text = $this->mail($to)->text;

        return preg_match('/[?&]token=([\w.-]+)/', $text, $match) === 1 ? $match[1] : throw new RuntimeException("No token in the mail to {$to}.");
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $path, array $headers = [], ?string $body = null): Exchange
    {
        return DifferentialTestCase::send($this->baseUrl, $method, $path, $headers, $body);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function json(string $method, string $path, array $data, array $headers = []): Exchange
    {
        return $this->send($method, $path, ['Content-Type' => 'application/json', ...$headers], (string) json_encode($data));
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $headers
     */
    public function form(string $path, array $fields, array $headers = []): Exchange
    {
        return $this->send('POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers], http_build_query($fields));
    }

    /**
     * A multipart/form-data POST. Each part is [name, contents] or, for a
     * file, [name, contents, file name].
     *
     * @param  list<array{0: string, 1: string, 2?: string}>  $parts
     * @param  array<string, string>  $headers
     */
    public function multipart(string $path, array $parts, array $headers = []): Exchange
    {
        $boundary = 'differential-boundary';
        $body = '';
        foreach ($parts as $part) {
            $disposition = "form-data; name=\"{$part[0]}\"".(isset($part[2]) ? "; filename=\"{$part[2]}\"" : '');
            $type = isset($part[2]) ? "\r\nContent-Type: application/octet-stream" : '';
            $body .= "--{$boundary}\r\nContent-Disposition: {$disposition}{$type}\r\n\r\n{$part[1]}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return $this->send('POST', $path, ['Content-Type' => "multipart/form-data; boundary={$boundary}", ...$headers], $body);
    }
}
