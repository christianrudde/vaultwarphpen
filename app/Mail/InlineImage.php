<?php

namespace App\Mail;

use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\DataPart;

/**
 * lettre's `Attachment::new_inline(name)`: an inline image whose Content-ID
 * is its bare name, which the templates refer to as `cid:<name>`. Symfony's
 * own content ids must look like addresses.
 */
final class InlineImage extends DataPart
{
    public function __construct(string $body, private readonly string $name)
    {
        parent::__construct($body, null, 'image/png');
        $this->asInline();
    }

    public function getPreparedHeaders(): Headers
    {
        $headers = parent::getPreparedHeaders();
        $headers->remove('Content-ID');
        $headers->addTextHeader('Content-ID', "<{$this->name}>");

        return $headers;
    }
}
