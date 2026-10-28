<?php

namespace Tests\Differential;

/**
 * A mail the SMTP sink of a server received (.github/scripts/smtp-sink.php),
 * decoded: the recipient, the subject and the text and HTML bodies.
 */
final readonly class CapturedMail
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $text,
        public string $html,
    ) {}

    public static function parse(string $raw): self
    {
        [$headers, $body] = self::split($raw);
        $parts = ['plain' => '', 'html' => ''];
        self::collect($headers, $body, $parts);

        return new self(
            self::address($headers['to'] ?? ''),
            self::decodeWords($headers['subject'] ?? ''),
            $parts['plain'],
            $parts['html'],
        );
    }

    /**
     * What the flows compare: subject and bodies, with Unix line endings.
     */
    public function exchange(): Exchange
    {
        return new Exchange(200, ['content-type' => 'application/json'], (string) json_encode(
            ['to' => $this->to, 'subject' => $this->subject, 'text' => $this->text, 'html' => $this->html],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @return array{array<string, string>, string} Headers by lower-case name, and the body
     */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $end = strpos($raw, "\n\n");
        $head = $end === false ? $raw : substr($raw, 0, $end);
        $body = $end === false ? '' : substr($raw, $end + 2);

        $headers = [];
        $name = null;
        foreach (explode("\n", $head) as $line) {
            if ($name !== null && ($line[0] ?? '') !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                // Folded header line.
                $headers[$name] .= ' '.trim($line);
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $headers[$name] = trim($value);
            }
        }

        return [$headers, $body];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array{plain: string, html: string}  $parts
     */
    private static function collect(array $headers, string $body, array &$parts): void
    {
        $type = strtolower($headers['content-type'] ?? 'text/plain');
        if (str_starts_with($type, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'] ?? '', $match)) {
            $sections = explode('--'.$match[1], $body);
            // Before the first boundary is the preamble, after the closing one the epilogue.
            foreach (array_slice($sections, 1) as $section) {
                if (str_starts_with($section, '--')) {
                    break;
                }
                [$partHeaders, $partBody] = self::split(ltrim($section, "\r\n"));
                self::collect($partHeaders, $partBody, $parts);
            }

            return;
        }

        $subtype = str_starts_with($type, 'text/html') ? 'html' : (str_starts_with($type, 'text/plain') ? 'plain' : null);
        if ($subtype === null) {
            return;
        }
        $parts[$subtype] = rtrim(str_replace("\r\n", "\n", match (strtolower($headers['content-transfer-encoding'] ?? '7bit')) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true),
            'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $body)),
            default => $body,
        }), "\n");
    }

    private static function decodeWords(string $value): string
    {
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    private static function address(string $value): string
    {
        return preg_match('/<([^>]+)>/', $value, $match) ? $match[1] : trim($value);
    }
}
