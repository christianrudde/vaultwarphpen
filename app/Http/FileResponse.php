<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rocket's NamedFile for a file without extension: the bytes, streamed,
 * without a Content-Type (see EmptyResponse for why it is removed here).
 */
final class FileResponse extends StreamedResponse
{
    public function __construct(string $path)
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
        $this->headers->remove('Content-Type');

        return $this;
    }
}
