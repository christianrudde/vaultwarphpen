<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Uri\WhatWg\Url;

/**
 * The cookie jar of the icon client (Jar in src/api/icons.rs, a
 * cookie_store::CookieStore): some sites redirect to check that cookies
 * work. Every cookie lives two minutes, whatever it asks for, so it cannot
 * be used for tracking. Vaultwarden keeps the jar in memory for all icon
 * downloads; here it lives in the cache.
 */
final class CookieJar
{
    private const string CACHE_KEY = 'icons:cookie-jar';

    private const int LIFETIME = 120;

    /**
     * @param  array<string, array{name: string, value: string, domain: string, hostOnly: bool, path: string, secure: bool, expires: int}>  $cookies  By domain, path and name
     */
    private function __construct(private array $cookies) {}

    public static function load(): self
    {
        $stored = Cache::get(self::CACHE_KEY);
        $cookies = [];
        foreach (is_array($stored) ? $stored : [] as $key => $cookie) {
            if (is_string($key) && is_array($cookie) && is_string($cookie['name'] ?? null) && is_string($cookie['value'] ?? null)
                && is_string($cookie['domain'] ?? null) && is_bool($cookie['hostOnly'] ?? null) && is_string($cookie['path'] ?? null)
                && is_bool($cookie['secure'] ?? null) && is_int($cookie['expires'] ?? null) && $cookie['expires'] > time()) {
                $cookies[$key] = ['name' => $cookie['name'], 'value' => $cookie['value'], 'domain' => $cookie['domain'], 'hostOnly' => $cookie['hostOnly'],
                    'path' => $cookie['path'], 'secure' => $cookie['secure'], 'expires' => $cookie['expires']];
            }
        }

        return new self($cookies);
    }

    public function save(): void
    {
        Cache::put(self::CACHE_KEY, $this->cookies, self::LIFETIME);
    }

    /**
     * set_cookies: the Set-Cookie headers of a response from the URL.
     *
     * @param  list<string>  $headers
     */
    public function store(array $headers, Url $url): void
    {
        $host = (string) $url->getAsciiHost();
        foreach ($headers as $header) {
            $cookie = self::parse($header, $host, $url);
            if ($cookie !== null) {
                $this->cookies["{$cookie['domain']};{$cookie['path']};{$cookie['name']}"] = $cookie;
            }
        }
    }

    /**
     * cookies: the Cookie header for a request to the URL, or null without cookies.
     */
    public function header(Url $url): ?string
    {
        $host = (string) $url->getAsciiHost();
        $path = $url->getPath();
        $pairs = [];
        foreach ($this->cookies as $cookie) {
            $domainMatches = $cookie['hostOnly'] ? $host === $cookie['domain'] : self::domainMatches($host, $cookie['domain']);
            if ($domainMatches && self::pathMatches($path, $cookie['path']) && $cookie['expires'] > time()
                && (! $cookie['secure'] || $url->getScheme() === 'https')) {
                $pairs[] = "{$cookie['name']}={$cookie['value']}";
            }
        }

        return $pairs === [] ? null : implode('; ', $pairs);
    }

    /**
     * @return array{name: string, value: string, domain: string, hostOnly: bool, path: string, secure: bool, expires: int}|null
     */
    private static function parse(string $header, string $host, Url $url): ?array
    {
        $parts = explode(';', $header);
        $pair = array_shift($parts);
        if (! str_contains($pair, '=')) {
            return null;
        }
        [$name, $value] = array_map(trim(...), explode('=', $pair, 2));
        if ($name === '') {
            return null;
        }

        $domain = null;
        $path = null;
        $secure = false;
        foreach ($parts as $attribute) {
            [$key, $attributeValue] = array_map(trim(...), explode('=', $attribute, 2) + [1 => '']);
            match (strtolower($key)) {
                'domain' => $domain = $attributeValue === '' ? null : strtolower(ltrim($attributeValue, '.')),
                'path' => $path = str_starts_with($attributeValue, '/') ? $attributeValue : null,
                'secure' => $secure = true,
                default => null,
            };
        }

        // A secure cookie only from a secure origin, a domain only from a host within it.
        if ($secure && $url->getScheme() !== 'https') {
            return null;
        }
        $isIp = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
        if ($domain !== null && ($domain === '' || ! self::domainMatches($host, $domain) || ($isIp && $domain !== $host))) {
            return null;
        }
        if ($path === null) {
            // The default path: the request path up to its last slash.
            $requestPath = $url->getPath();
            $slash = strrpos($requestPath, '/');
            $path = ! str_starts_with($requestPath, '/') || $slash === 0 || $slash === false ? '/' : substr($requestPath, 0, $slash);
        }

        return ['name' => $name, 'value' => $value, 'domain' => $domain ?? $host, 'hostOnly' => $domain === null, 'path' => $path,
            'secure' => $secure, 'expires' => time() + self::LIFETIME];
    }

    private static function domainMatches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, ".{$domain}");
    }

    private static function pathMatches(string $requestPath, string $cookiePath): bool
    {
        return $requestPath === $cookiePath
            || (str_starts_with($requestPath, $cookiePath) && (str_ends_with($cookiePath, '/') || ($requestPath[strlen($cookiePath)] ?? '') === '/'));
    }
}
