<?php

namespace App\Support;

use App\Config\Config;
use App\Http\ApiError;
use Uri\WhatWg\Url;

/**
 * src/http_client.rs: outgoing requests like Vaultwarden's reqwest client,
 * with its user agent, a 10-second timeout and at most five redirects.
 * Requests made with make_http_request (enforceBlock) refuse hosts that
 * HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS or HTTP_REQUEST_BLOCK_REGEX block.
 */
final class HttpClient
{
    /**
     * Redirects reqwest follows (tower-http's FollowRedirect).
     */
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    /**
     * @param  array<string, string>  $headers
     * @return array{int, string, array<string, string>} The status, the body and the headers of the last response, by lower case name
     *
     * @throws ApiError When the host is blocked or the request fails
     */
    public static function send(string $method, string $url, array $headers = [], ?string $body = null, bool $enforceBlock = true, bool $redirects = true): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw ApiError::message('Invalid host');
        }
        if ($enforceBlock) {
            self::checkHost(trim($host, '[]'));
        }

        $lines = [];
        // reqwest's client sends Accept: */* unless a request sets its own.
        foreach (['User-Agent' => 'Vaultwarden', 'Accept' => '*/*', ...$headers] as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'content' => $body ?? '',
            'timeout' => 10,
            'follow_location' => $redirects ? 1 : 0,
            'max_redirects' => 6,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ], 'ssl' => self::sslOptions()]);

        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $response = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        $responseHeaders = http_get_last_response_headers() ?? [];
        if ($response === false || $responseHeaders === []) {
            throw ApiError::message('Error sending request', logMessage: $error ?? "Request to {$url} failed");
        }

        // After redirects the headers of each response follow one another.
        $status = 0;
        $last = [];
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
                $last = [];
            } elseif (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $last[strtolower(trim($name))] = trim($value);
            }
        }

        return [$status, $response, $last];
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
        $previous = [];
        while (true) {
            if (! in_array($url->getScheme(), ['http', 'https'], true)) {
                throw new HttpRequestFailed("URL scheme is not allowed: {$url->toAsciiString()}");
            }
            $host = (string) $url->getAsciiHost();
            if (! str_starts_with($host, '[') && filter_var($host, FILTER_VALIDATE_IP) === false) {
                self::checkResolvedHost($host);
            }

            $lines = [];
            $cookie = $cookies?->header($url);
            foreach ($cookie === null ? $headers : [...$headers, 'Cookie' => $cookie] as $name => $value) {
                $lines[] = "{$name}: {$value}";
            }
            $lines[] = 'Accept-Encoding: gzip, deflate';
            $remaining = max($deadline - microtime(true), 0.001);
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => $lines,
                'timeout' => $remaining,
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ], 'ssl' => self::sslOptions()]);

            $error = null;
            set_error_handler(static function (int $level, string $message) use (&$error): bool {
                $error = $message;

                return true;
            });
            try {
                $stream = fopen($url->toAsciiString(), 'rb', false, $context);
            } finally {
                restore_error_handler();
            }
            if ($stream === false) {
                throw new HttpRequestFailed($error ?? "Request to {$url->toAsciiString()} failed");
            }

            $wrapperData = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            [$status, $responseHeaders, $setCookies] = self::parseHeaders(is_array($wrapperData) ? $wrapperData : []);
            $cookies?->store($setCookies, $url);

            $location = isset($responseHeaders['location']) && in_array($status, self::REDIRECTS, true)
                ? Url::parse($responseHeaders['location'], $url)
                : null;
            if ($location === null) {
                $response = new HttpResponse($url, $status, $responseHeaders, $stream, $deadline);
                if ($status >= 400 && $status < 600) {
                    $response->close();

                    throw new HttpRequestFailed("HTTP status {$status} for URL ({$url->toAsciiString()})");
                }

                return $response;
            }
            fclose($stream);

            // The redirect policy of get_reqwest_client_builder.
            $previous[] = $url;
            if (count($previous) >= 5) {
                throw new HttpRequestFailed('Too many redirects');
            }
            $reason = self::blockReason((string) $location->getAsciiHost());
            if ($reason !== null) {
                throw new BlockedHost($reason);
            }
            // Referer: the previous URL, but never from HTTPS to HTTP (the one set before stays then).
            if (! ($location->getScheme() === 'http' && $url->getScheme() === 'https')) {
                $headers['Referer'] = $url->withUsername(null)->withPassword(null)->withFragment(null)->toAsciiString();
            }
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
     * should_block_host: why the host (from validHost) is blocked, or null.
     * An address that is not global, or a name or address that the block
     * regex matches; names are not resolved here.
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
        if ($regex !== null && preg_match('/'.str_replace('/', '\/', $regex).'/u', $subject) === 1) {
            return 'Blocked domain: \''.($domain === null ? $subject : "{$domain} ({$subject})").'\' matched HTTP_REQUEST_BLOCK_REGEX';
        }

        return null;
    }

    /**
     * The checks of the DNS resolver (pre_resolve and post_resolve): a valid
     * name that is not blocked, resolving only to addresses that are not.
     *
     * @throws BlockedHost
     */
    private static function checkResolvedHost(string $domain): void
    {
        if (self::validHost($domain) === null) {
            throw new BlockedHost("Invalid host: '{$domain}' contains invalid characters or exceeds the maximum length");
        }
        if (self::blockReason($domain) !== null) {
            throw new BlockedHost("Blocked domain: '{$domain}' matched HTTP_REQUEST_BLOCK_REGEX");
        }

        $config = app(Config::class);
        if (! $config->bool('http_request_block_non_global_ips') && $config->optionalString('http_request_block_regex') === null) {
            return;
        }
        foreach (gethostbynamel($domain) ?: [] as $ip) {
            $reason = self::blockReason($ip, $domain);
            if ($reason !== null) {
                throw new BlockedHost($reason);
            }
        }
    }

    /**
     * should_block_host for make_http_request: an address that is not
     * global, or a name the block regex matches. Names are checked against
     * their addresses as well.
     *
     * @throws ApiError
     */
    private static function checkHost(string $host): void
    {
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $reason = self::blockReason($isIp && str_contains($host, ':') ? "[{$host}]" : $host);
        if ($reason !== null) {
            throw ApiError::message($reason);
        }
        if (! $isIp) {
            try {
                self::checkResolvedHost($host);
            } catch (BlockedHost $blocked) {
                throw ApiError::message($blocked->getMessage());
            }
        }
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
