<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * A Set-Cookie header written like the cookie crate that Rocket uses:
 * attributes in its order and spelling, an empty value for a removal.
 * Symfony sends cookies by casting them to strings.
 */
final class RocketCookie extends Cookie
{
    private ?string $header = null;

    /**
     * A session cookie as Vaultwarden adds it to the CookieJar: HttpOnly,
     * SameSite=Strict, Secure over HTTPS, valid for the given seconds.
     */
    public static function session(string $name, string $value, string $path, int $maxAge, bool $secure): self
    {
        $cookie = new self($name, $value, time() + $maxAge, $path, null, $secure, true, true, self::SAMESITE_STRICT);
        $cookie->header = "{$name}={$value}; HttpOnly; SameSite=Strict".($secure ? '; Secure' : '')."; Path={$path}; Max-Age={$maxAge}";

        return $cookie;
    }

    /**
     * CookieJar::remove: SameSite=Lax unless given, an empty value, Max-Age=0
     * and an expiry a year ago.
     */
    public static function removal(string $name, string $path): self
    {
        $expires = time() - 365 * 86_400;
        $cookie = new self($name, '', $expires, $path, null, false, false, true, self::SAMESITE_LAX);
        $cookie->header = "{$name}=; SameSite=Lax; Path={$path}; Max-Age=0; Expires=".gmdate('D, d M Y H:i:s', $expires).' GMT';

        return $cookie;
    }

    public function __toString(): string
    {
        return $this->header ?? parent::__toString();
    }
}
