<?php

namespace App\Config;

/**
 * The parts of the WHATWG URL parsing (Rust's url crate) that the settings
 * rely on: validity, origin and path.
 */
final class Url
{
    /**
     * Schemes with a host and their default ports.
     */
    private const array SPECIAL_SCHEMES = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];

    /**
     * Characters of the path percent-encode set besides C0 controls and non-ASCII.
     */
    private const string PATH_ENCODE = ' "#<>?`{}';

    public static function isValid(string $url): bool
    {
        return self::parse($url) !== null;
    }

    /**
     * The ASCII serialization of the origin (`scheme://host[:port]`), "null" for
     * an opaque origin and "" for an invalid URL (extract_url_origin).
     */
    public static function origin(string $url): string
    {
        $parts = self::parse($url);
        if ($parts === null) {
            return '';
        }
        if (! isset(self::SPECIAL_SCHEMES[$parts['scheme']]) || $parts['host'] === '') {
            return 'null';
        }

        $port = $parts['port'] !== null && $parts['port'] !== self::SPECIAL_SCHEMES[$parts['scheme']] ? ":{$parts['port']}" : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }

    /**
     * The path without any trailing `/`, "" for an invalid URL (extract_url_path).
     */
    public static function pathWithoutTrailingSlash(string $url): string
    {
        return rtrim(self::parse($url)['path'] ?? '', '/');
    }

    /**
     * The host when the URL has a domain name rather than an IP address.
     */
    public static function domain(string $url): ?string
    {
        $host = self::parse($url)['host'] ?? '';

        return $host === '' || str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false ? null : $host;
    }

    /**
     * @return array{scheme: string, host: string, port: int|null, path: string}|null
     */
    private static function parse(string $url): ?array
    {
        // Leading and trailing C0 controls and spaces are stripped, tabs and newlines removed.
        $url = (string) preg_replace('/[\t\n\r]/', '', trim($url, "\x00..\x20"));
        if (! preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):(.*)$/s', $url, $match)) {
            return null;
        }

        $scheme = strtolower($match[1]);
        $rest = $match[2];

        if (! isset(self::SPECIAL_SCHEMES[$scheme])) {
            $path = (string) preg_replace('/[?#].*$/s', '', $rest);

            return ['scheme' => $scheme, 'host' => '', 'port' => null, 'path' => $path];
        }

        // Special schemes treat backslashes like slashes and require a host.
        $rest = ltrim(str_replace('\\', '/', $rest), '/');
        $rest = (string) preg_replace('/[?#].*$/s', '', $rest);
        $slash = strpos($rest, '/');
        $authority = $slash === false ? $rest : substr($rest, 0, $slash);
        $path = $slash === false ? '/' : substr($rest, $slash);

        // Credentials are allowed but not part of the origin.
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);

        if (! preg_match('/^(\[[0-9a-fA-F:.]+\]|[^:\[\]]*)(?::([0-9]*))?$/', $hostPort, $hostMatch)) {
            return null;
        }
        $host = self::host($hostMatch[1]);
        $port = ($hostMatch[2] ?? '') === '' ? null : (int) ltrim($hostMatch[2], '0');
        if ($host === null || $host === '' || ($port !== null && $port > 65_535)) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => self::normalizePath($path)];
    }

    private static function host(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);

            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? null : '['.inet_ntop((string) inet_pton($address)).']';
        }

        $host = rawurldecode($host);
        if (preg_match('/[\x00-\x20#%\/:<>?@\[\\\\\]^|]/', $host)) {
            return null;
        }
        if (preg_match('/[^\x00-\x7F]/', $host)) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }

        return strtolower($host);
    }

    /**
     * Remove dot segments and percent-encode like the URL parser does.
     */
    private static function normalizePath(string $path): string
    {
        $output = [];
        $segments = explode('/', substr($path, 1));
        foreach ($segments as $index => $segment) {
            $last = $index === array_key_last($segments);
            if (in_array(strtolower($segment), ['.', '%2e'], true)) {
                if ($last) {
                    $output[] = '';
                }

                continue;
            }
            if (in_array(strtolower($segment), ['..', '.%2e', '%2e.', '%2e%2e'], true)) {
                array_pop($output);
                if ($last) {
                    $output[] = '';
                }

                continue;
            }
            $output[] = $segment;
        }

        $encoded = (string) preg_replace_callback(
            '/[\x00-\x1F\x7F-\xFF'.preg_quote(self::PATH_ENCODE, '/').']/',
            static fn (array $match): string => '%'.strtoupper(bin2hex($match[0])),
            '/'.implode('/', $output),
        );

        return $encoded;
    }
}
