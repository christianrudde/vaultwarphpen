<?php

namespace App\Support;

use App\Config\Url;

/**
 * Email address checks of Vaultwarden (util::is_valid_email, based on the
 * email_address crate).
 */
final class EmailAddress
{
    private const string ATOM = "[A-Za-z0-9!#$%&'*+\\/=?^_`{|}~-]+";

    /**
     * A syntactically valid address whose domain is a domain name.
     */
    public static function isValid(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        $dotAtom = '/^'.self::ATOM.'(?:\.'.self::ATOM.')*$/D';
        $quoted = '/^"(?:[^"\\\\\r\n]|\\\\.)*"$/D';
        if ($local === '' || strlen($local) > 64 || (! preg_match($dotAtom, $local) && ! preg_match($quoted, $local))) {
            return false;
        }
        if ($domain === '' || strlen($domain) > 254 || ! preg_match($dotAtom, $domain)) {
            return false;
        }

        return Url::domain("https://{$domain}") !== null
            && Url::pathWithoutTrailingSlash("https://{$domain}") === ''
            && ! str_contains($domain, '?');
    }
}
