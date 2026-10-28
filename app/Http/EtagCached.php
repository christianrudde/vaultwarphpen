<?php

namespace App\Http;

use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * util::EtagCached: the content with a quoted ETag, or 304 Not Modified when
 * If-None-Match names it. Both carry the content type, the ETag and
 * `Cache-Control: public, no-cache`.
 *
 * Symfony's prepare() would drop the content type of a 304 and add a charset
 * to text types; the exact type is set again afterwards.
 */
final class EtagCached extends Response
{
    public function __construct(
        LaravelRequest $request,
        string $content,
        private readonly string $contentType,
        string $etag,
    ) {
        $etag = "\"{$etag}\"";
        // Several tags may be listed; weak ones are compared without their W/ prefix.
        $matches = false;
        foreach (explode(',', (string) $request->headers->get('If-None-Match')) as $tag) {
            if (preg_replace('#^(W/)+#', '', trim($tag)) === $etag) {
                $matches = true;
            }
        }

        parent::__construct($matches ? '' : $content, $matches ? 304 : 200, [
            'Content-Type' => $contentType,
            'Etag' => $etag,
            'Cache-Control' => 'public, no-cache',
        ]);
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);
        $this->headers->set('Content-Type', $this->contentType);

        return $this;
    }

    /**
     * PHP would add its default charset to a text type without one, like text/javascript.
     */
    public function sendHeaders(?int $statusCode = null): static
    {
        $charset = (string) ini_get('default_charset');
        ini_set('default_charset', '');
        try {
            return parent::sendHeaders($statusCode);
        } finally {
            ini_set('default_charset', $charset);
        }
    }
}
