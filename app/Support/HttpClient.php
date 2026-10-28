<?php

namespace App\Support;

use App\Config\Config;
use App\Http\ApiError;
use Uri\WhatWg\Url;

/**
 * src/http_client.rs: outgoing requests like Vaultwarden's reqwest client,
 * with its user agent, its redirect policy and one timeout for the whole
 * request. A name is resolved once, every address it resolves to is
 * checked like the DNS resolver checks it, and the connection goes to
 * those addresses only. Requests made with make_http_request
 * (enforceBlock) refuse hosts that HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS or
 * HTTP_REQUEST_BLOCK_REGEX block, at every redirect.
 */
final class HttpClient
{
    /**
     * Redirects reqwest follows (tower-http's FollowRedirect).
     */
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    /**
     * The timeout of the client of make_http_request, in seconds.
     */
    private const int TIMEOUT = 10;

    /**
     * What reqwest removes when a redirect leads to another host or port (remove_sensitive_headers).
     */
    private const array SENSITIVE_HEADERS = ['authorization', 'cookie', 'cookie2', 'proxy-authorization', 'www-authenticate'];

    /**
     * The most a response head may take before the request fails.
     */
    private const int MAX_HEAD = 65_536;

    /**
     * @param  array<string, string>  $headers
     * @return array{int, string, array<string, string>} The status, the body and the headers of the last response, by lower case name
     *
     * @throws ApiError When a host is blocked or the request fails
     */
    public static function send(string $method, string $url, array $headers = [], ?string $body = null, bool $enforceBlock = true, bool $redirects = true): array
    {
        // url::Url::parse, which lower-cases the host and turns it into ASCII.
        $current = Url::parse($url) ?? throw ApiError::message('Invalid URL');
        $host = $current->getAsciiHost();
        if ($host === null || $host === '') {
            throw ApiError::message('Invalid host');
        }

        $deadline = microtime(true) + self::TIMEOUT;
        // reqwest's client sends Accept: */* unless a request sets its own.
        $headers = ['User-Agent' => 'Vaultwarden', 'Accept' => '*/*', ...$headers];
        $previous = 0;
        try {
            if ($enforceBlock && ($reason = self::blockReason($host)) !== null) {
                throw new BlockedHost($reason);
            }
            while (true) {
                $response = self::request($method, $current, $headers, $body, $deadline, $enforceBlock);
                $location = $redirects ? self::location($response) : null;
                if ($location === null) {
                    $content = $response->read(PHP_INT_MAX);
                    if (! $response->complete()) {
                        throw new HttpRequestFailed("Reading the response of {$current->toAsciiString()} failed or timed out");
                    }

                    return [$response->status, $content, $response->headers];
                }
                $response->close();

                self::checkRedirect(++$previous, $location, $enforceBlock);
                // 301, 302 and 303 go on without the body, as a GET unless it was a HEAD.
                if (in_array($response->status, [301, 302, 303], true)) {
                    $method = in_array($method, ['GET', 'HEAD'], true) ? $method : 'GET';
                    $body = null;
                    $headers = self::without($headers, ['transfer-encoding', 'content-encoding', 'content-type', 'content-length']);
                }
                $headers = self::redirectHeaders($headers, $current, $location);
                $current = $location;
            }
        } catch (BlockedHost $blocked) {
            throw ApiError::message($blocked->getMessage());
        } catch (HttpRequestFailed $failure) {
            throw ApiError::message('Error sending request', logMessage: $failure->getMessage());
        }
    }

    /**
     * A GET with a client of get_reqwest_client_builder(true), as icons.rs
     * builds it: up to four redirects, each to a host that is not blocked,
     * cookies from and into the jar, and one timeout for everything,
     * reading the body included. The body is left to the caller.
     *
     * @param  array<string, string>  $headers
     *
     * @throws BlockedHost When a host is invalid or blocked, as the redirect policy and the DNS resolver refuse them
     * @throws HttpRequestFailed When the request fails or answers with an error status
     */
    public static function get(Url $url, array $headers, float $timeout, ?CookieJar $cookies = null): HttpResponse
    {
        $deadline = microtime(true) + $timeout;
        $previous = 0;
        while (true) {
            $cookie = $cookies?->header($url);
            $requestHeaders = $cookie === null ? $headers : [...$headers, 'Cookie' => $cookie];
            $response = self::request('GET', $url, [...$requestHeaders, 'Accept-Encoding' => 'gzip, deflate'], null, $deadline, true);
            $cookies?->store($response->cookies, $url);

            $location = self::location($response);
            if ($location === null) {
                if ($response->status >= 400 && $response->status < 600) {
                    $response->close();

                    throw new HttpRequestFailed("HTTP status {$response->status} for URL ({$url->toAsciiString()})");
                }

                return $response;
            }
            $response->close();

            self::checkRedirect(++$previous, $location, true);
            $headers = self::redirectHeaders($headers, $url, $location);
            $url = $location;
        }
    }

    /**
     * get_valid_host: the host as the url crate's Host::parse reads it
     * (IPv6 in brackets, IPv4 in dotted decimal, a domain in ASCII), or
     * null when it is invalid or not a proper DNS name.
     */
    public static function validHost(string $host): ?string
    {
        // Host::parse refuses what would make the URL below mean something else.
        if ($host === '' || preg_match('/[\x00-\x20\/\\\\?#@]/', $host) === 1
            || (str_contains($host, ':') && ! (str_starts_with($host, '[') && str_ends_with($host, ']')))) {
            return null;
        }
        $parsed = Url::parse("https://{$host}/")?->getAsciiHost();
        if ($parsed === null || $parsed === '') {
            return null;
        }
        if (str_starts_with($parsed, '[') || filter_var($parsed, FILTER_VALIDATE_IP) !== false) {
            return $parsed;
        }

        $domain = strtolower($parsed);
        if (strlen($domain) > 253) {
            return null;
        }
        foreach (explode('.', $domain) as $label) {
            if ($label === '' || strlen($label) > 63 || str_starts_with($label, '-') || str_ends_with($label, '-')
                || preg_match('/^[a-z0-9-]+$/D', $label) !== 1) {
                return null;
            }
        }

        return $domain;
    }

    /**
     * should_block_host: why the host (from validHost, or an address) is
     * blocked, or null. An address that is not global, or a name or address
     * that the block regex matches; names are not resolved here.
     */
    public static function blockReason(string $host, ?string $domain = null): ?string
    {
        $config = app(Config::class);
        $ip = str_starts_with($host, '[') ? substr($host, 1, -1) : (filter_var($host, FILTER_VALIDATE_IP) === false ? null : $host);
        if ($ip !== null) {
            // Ipv6Addr's Display, which writes IPv4-mapped addresses with dots.
            $ip = (string) inet_ntop((string) inet_pton($ip));
            if ($config->bool('http_request_block_non_global_ips') && ! IpNetwork::isGlobal($ip)) {
                return $domain === null ? "IP '{$ip}' is not a global IP!" : "IP {$ip} for domain '{$domain}' is not a global IP!";
            }
        }

        $regex = $config->optionalString('http_request_block_regex');
        $subject = $ip ?? $host;
        // The delimiters ConfigValidator checks the regex with. Rust's regex cannot fail
        // while matching; PCRE can (backtracking limits), and then the host stays blocked.
        if ($regex !== null && @preg_match("\x01{$regex}\x01u", $subject) !== 0) {
            return 'Blocked domain: \''.($domain === null ? $subject : "{$domain} ({$subject})").'\' matched HTTP_REQUEST_BLOCK_REGEX';
        }

        return null;
    }

    /**
     * One request on a connection of its own, up to the head of the
     * response; the body is left in the response.
     *
     * @param  array<string, string>  $headers
     *
     * @throws BlockedHost
     * @throws HttpRequestFailed
     */
    private static function request(string $method, Url $url, array $headers, ?string $body, float $deadline, bool $enforceBlock): HttpResponse
    {
        $scheme = $url->getScheme();
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new HttpRequestFailed("URL scheme is not allowed: {$url->toAsciiString()}");
        }
        $host = (string) $url->getAsciiHost();
        $port = $url->getPort();
        $stream = self::connect($scheme === 'https', $host, $port ?? ($scheme === 'https' ? 443 : 80), $deadline, $enforceBlock);

        $query = $url->getQuery();
        $head = "{$method} {$url->getPath()}".($query === null ? '' : "?{$query}")." HTTP/1.1\r\n"
            .'Host: '.$host.($port === null ? '' : ":{$port}")."\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        if ($body !== null) {
            $head .= 'Content-Length: '.strlen($body)."\r\n";
        }
        self::write($stream, "{$head}Connection: close\r\n\r\n".($body ?? ''), $deadline);

        [$status, $responseHeaders, $cookies, $rest] = self::readHead($stream, $deadline);
        $chunked = str_contains(strtolower($responseHeaders['transfer-encoding'] ?? ''), 'chunked');
        $length = match (true) {
            $method === 'HEAD', $status === 204, $status === 304 => 0,
            $chunked => null,
            preg_match('/^\d{1,18}$/D', $responseHeaders['content-length'] ?? '') === 1 => (int) $responseHeaders['content-length'],
            default => null,
        };

        return new HttpResponse($url, $status, $responseHeaders, $stream, $deadline, $cookies, $rest, $length, $chunked && $length === null);
    }

    /**
     * A connection to the host: to its address, or to the addresses its
     * name resolves to, in the order the resolver sorts them.
     *
     * @return resource
     *
     * @throws BlockedHost
     * @throws HttpRequestFailed
     */
    private static function connect(bool $tls, string $host, int $port, float $deadline, bool $enforceBlock): mixed
    {
        $address = str_starts_with($host, '[') ? substr($host, 1, -1) : (filter_var($host, FILTER_VALIDATE_IP) === false ? null : $host);
        if ($address !== null && $enforceBlock && ($reason = self::blockReason($host)) !== null) {
            throw new BlockedHost($reason);
        }
        $addresses = $address === null ? self::resolve($host, $enforceBlock) : [$address];

        // The certificate is checked for the name, which is also sent as SNI.
        $context = stream_context_create(['ssl' => [...self::sslOptions(), 'peer_name' => $address ?? $host]]);
        $failures = [];
        foreach ($addresses as $ip) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $failures[] = 'operation timed out';

                break;
            }
            $target = str_contains($ip, ':') ? "[{$ip}]:{$port}" : "{$ip}:{$port}";
            $stream = @stream_socket_client(($tls ? 'ssl' : 'tcp')."://{$target}", $errno, $error, $remaining, STREAM_CLIENT_CONNECT, $context);
            if ($stream !== false) {
                return $stream;
            }
            $failures[] = "{$target}: {$error}";
        }

        throw new HttpRequestFailed("Connecting to {$host}:{$port} failed: ".implode('; ', $failures));
    }

    /**
     * The DNS resolver (pre_resolve and post_resolve): a valid name that is
     * not blocked, resolving only to addresses that are not, with IPv4
     * first unless DNS_PREFER_IPV6.
     *
     * @return list<string>
     *
     * @throws BlockedHost
     * @throws HttpRequestFailed
     */
    private static function resolve(string $domain, bool $enforceBlock): array
    {
        if (self::validHost($domain) === null) {
            throw new BlockedHost("Invalid host: '{$domain}' contains invalid characters or exceeds the maximum length");
        }
        if ($enforceBlock && self::blockReason($domain) !== null) {
            throw new BlockedHost("Blocked domain: '{$domain}' matched HTTP_REQUEST_BLOCK_REGEX");
        }

        $addresses = app(DnsResolver::class)->lookup($domain);
        if ($addresses === []) {
            throw new HttpRequestFailed("dns error: no addresses for {$domain}");
        }
        if ($enforceBlock) {
            foreach ($addresses as $ip) {
                $reason = self::blockReason($ip, $domain);
                if ($reason !== null) {
                    throw new BlockedHost($reason);
                }
            }
        }

        $preferIpv6 = app(Config::class)->bool('dns_prefer_ipv6');
        usort($addresses, static fn (string $a, string $b): int => (str_contains($a, ':') <=> str_contains($b, ':')) * ($preferIpv6 ? -1 : 1));

        return $addresses;
    }

    /**
     * @param  resource  $stream
     *
     * @throws HttpRequestFailed
     */
    private static function write($stream, string $data, float $deadline): void
    {
        while ($data !== '') {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new HttpRequestFailed('Sending the request timed out');
            }
            stream_set_timeout($stream, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000));
            $written = @fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new HttpRequestFailed('Sending the request failed');
            }
            $data = substr($data, $written);
        }
    }

    /**
     * The head of the response, skipping interim (1xx) ones, and what came after it.
     *
     * @param  resource  $stream
     * @return array{int, array<string, string>, list<string>, string}
     *
     * @throws HttpRequestFailed
     */
    private static function readHead($stream, float $deadline): array
    {
        // Reads that wait no longer than the deadline: a server may send its head byte by byte.
        stream_set_blocking($stream, false);
        $buffer = '';
        while (true) {
            if (preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                $lines = preg_split('/\r?\n/', substr($buffer, 0, $match[0][1])) ?: [];
                $buffer = substr($buffer, $match[0][1] + strlen($match[0][0]));
                [$status, $headers, $cookies] = self::parseHeaders($lines);
                if (preg_match('#^HTTP/\d(?:\.\d)?\s+\d{3}#', $lines[0] ?? '') !== 1) {
                    throw new HttpRequestFailed('The response has no valid status line');
                }
                if ($status >= 100 && $status < 200) {
                    continue;
                }

                return [$status, $headers, $cookies, $buffer];
            }
            if (strlen($buffer) > self::MAX_HEAD) {
                throw new HttpRequestFailed('The head of the response is too large');
            }

            $received = HttpResponse::receive($stream, $deadline);
            if (! is_string($received)) {
                fclose($stream);

                throw new HttpRequestFailed($received === false ? 'Waiting for the response timed out' : 'The connection closed before the response');
            }
            $buffer .= $received;
        }
    }

    /**
     * The URL a redirect leads to, or null when the response is none.
     */
    private static function location(HttpResponse $response): ?Url
    {
        $location = $response->headers['location'] ?? null;

        return $location !== null && in_array($response->status, self::REDIRECTS, true) ? Url::parse($location, $response->url) : null;
    }

    /**
     * The redirect policy of get_reqwest_client_builder: at most four
     * redirects, each to a host that is not blocked.
     *
     * @throws BlockedHost
     * @throws HttpRequestFailed
     */
    private static function checkRedirect(int $previous, Url $location, bool $enforceBlock): void
    {
        if ($previous >= 5) {
            throw new HttpRequestFailed('Too many redirects');
        }
        $host = $location->getAsciiHost();
        if ($host === null || $host === '') {
            throw new HttpRequestFailed('Invalid host');
        }
        if ($enforceBlock && ($reason = self::blockReason($host)) !== null) {
            throw new BlockedHost($reason);
        }
    }

    /**
     * The headers after a redirect: credentials stay with their host and
     * port, and the Referer is the previous URL, but never from HTTPS to
     * HTTP (the one set before stays then).
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function redirectHeaders(array $headers, Url $previous, Url $next): array
    {
        $port = static fn (Url $url): ?int => $url->getPort() ?? ['http' => 80, 'https' => 443][$url->getScheme()] ?? null;
        if ($next->getAsciiHost() !== $previous->getAsciiHost() || $port($next) !== $port($previous)) {
            $headers = self::without($headers, self::SENSITIVE_HEADERS);
        }
        if (! ($next->getScheme() === 'http' && $previous->getScheme() === 'https')) {
            $headers = [...self::without($headers, ['referer']),
                'Referer' => $previous->withUsername(null)->withPassword(null)->withFragment(null)->toAsciiString()];
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $names  Lower case
     * @return array<string, string>
     */
    private static function without(array $headers, array $names): array
    {
        return array_filter($headers, static fn (string $name): bool => ! in_array(strtolower($name), $names, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Like rustls-native-certs, the trusted roots may come from SSL_CERT_FILE or SSL_CERT_DIR.
     *
     * @return array<string, string>
     */
    private static function sslOptions(): array
    {
        return array_filter(['cafile' => getenv('SSL_CERT_FILE') ?: null, 'capath' => getenv('SSL_CERT_DIR') ?: null]);
    }

    /**
     * The status, the last value of each header by lower case name, and the Set-Cookie headers.
     *
     * @param  array<mixed>  $lines
     * @return array{int, array<string, string>, list<string>}
     */
    private static function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        $cookies = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $headers[$name] = trim($value);
                if ($name === 'set-cookie') {
                    $cookies[] = trim($value);
                }
            }
        }

        return [$status, $headers, $cookies];
    }
}
