<?php

namespace App\Support;

use App\Config\Config;
use App\Http\ApiError;

/**
 * src/http_client.rs: outgoing requests like Vaultwarden's reqwest client,
 * with its user agent, a 10-second timeout and at most five redirects.
 * Requests made with make_http_request (enforceBlock) refuse hosts that
 * HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS or HTTP_REQUEST_BLOCK_REGEX block.
 */
final class HttpClient
{
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
        // Like rustls-native-certs, the trusted roots may come from SSL_CERT_FILE or SSL_CERT_DIR.
        $ssl = array_filter(['cafile' => getenv('SSL_CERT_FILE') ?: null, 'capath' => getenv('SSL_CERT_DIR') ?: null]);
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'content' => $body ?? '',
            'timeout' => 10,
            'follow_location' => $redirects ? 1 : 0,
            'max_redirects' => 6,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ], 'ssl' => $ssl]);

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
     * should_block_host: an address that is not global, or a name the block
     * regex matches. Names are checked against their addresses as well.
     *
     * @throws ApiError
     */
    private static function checkHost(string $host): void
    {
        $config = app(Config::class);
        $blockNonGlobal = $config->bool('http_request_block_non_global_ips');
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($isIp && $blockNonGlobal && ! IpNetwork::isGlobal($host)) {
            throw ApiError::message("IP '{$host}' is not a global IP!");
        }
        $regex = $config->optionalString('http_request_block_regex');
        if ($regex !== null && preg_match('/'.str_replace('/', '\/', $regex).'/u', $host) === 1) {
            throw ApiError::message("Blocked domain: '{$host}' matched HTTP_REQUEST_BLOCK_REGEX");
        }
        if (! $isIp && $blockNonGlobal) {
            foreach (gethostbynamel($host) ?: [] as $ip) {
                if (! IpNetwork::isGlobal($ip)) {
                    throw ApiError::message("IP {$ip} for domain '{$host}' is not a global IP!");
                }
            }
        }
    }
}
