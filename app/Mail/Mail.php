<?php

namespace App\Mail;

use App\Http\ApiError;

/**
 * Vaultwarden's mails (src/mail.rs). Sending is not ported yet: every mail
 * fails like a transport error would, so the callers' error handling (log
 * and continue, or fail the request) already behaves as in Vaultwarden.
 */
final class Mail
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ApiError
     */
    public static function send(string $template, string $to, array $data = []): void
    {
        throw ApiError::message('Sending mail is not implemented yet');
    }
}
