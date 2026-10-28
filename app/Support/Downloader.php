<?php

namespace App\Support;

use RuntimeException;

/**
 * Downloads for the installation (the web vault image). Tests put a fake in
 * the container.
 */
interface Downloader
{
    /**
     * GET the URL and write the body of the response to the file, whatever
     * its status. Redirects are followed, without the Authorization header
     * once they lead to another host.
     *
     * @param  array<string, string>  $headers
     * @return int The status of the response
     *
     * @throws RuntimeException When there is no response
     */
    public function download(string $url, array $headers, string $file): int;
}
