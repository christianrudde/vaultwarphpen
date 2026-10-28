<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rocket's NamedFile: the bytes, streamed, with the content type Rocket knows
 * for the file's extension, or without a Content-Type (see EmptyResponse for
 * why it is removed here).
 */
final class FileResponse extends StreamedResponse
{
    public function __construct(string $path, private readonly ?string $contentType = null)
    {
        parent::__construct(static function () use ($path): void {
            $handle = fopen($path, 'rb');
            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }
        }, 200, ['Content-Length' => (string) filesize($path)]);
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);
        if ($this->contentType === null) {
            $this->headers->remove('Content-Type');
        } else {
            $this->headers->set('Content-Type', $this->contentType);
        }

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
