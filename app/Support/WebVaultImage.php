<?php

namespace App\Support;

use PharData;
use PharException;
use PharFileInfo;
use RecursiveIteratorIterator;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * The web vault that Vaultwarden's Docker images ship: the bw_web_builds image
 * its Dockerfile copies /web-vault from, pinned by the digest of
 * docker/DockerSettings.yaml. Pulled from a registry like `docker pull`
 * (linux/amd64, as the Dockerfile asks for), every part checked against its
 * digest, and the layers' web-vault folder unpacked in order.
 */
final readonly class WebVaultImage
{
    /**
     * Where the image is published: the registry, the repository and the
     * address that hands out anonymous tokens for pulling it.
     *
     * @var array<string, array{string, string, string}>
     */
    public const array REGISTRIES = [
        'ghcr.io' => ['https://ghcr.io', 'dani-garcia/bw_web_builds', 'https://ghcr.io/token?scope=repository:dani-garcia/bw_web_builds:pull'],
        'docker.io' => ['https://registry-1.docker.io', 'vaultwarden/web-vault', 'https://auth.docker.io/token?service=registry.docker.io&scope=repository:vaultwarden/web-vault:pull'],
    ];

    private const array MANIFEST_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    private const array LAYER_TYPES = [
        'application/vnd.oci.image.layer.v1.tar+gzip',
        'application/vnd.docker.image.rootfs.diff.tar.gzip',
    ];

    public function __construct(
        public string $version,
        public string $digest,
        private Downloader $downloader,
    ) {
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
            throw new RuntimeException("The web vault digest {$digest} is not a SHA-256 digest.");
        }
    }

    /**
     * Pull the image from one of the registries and unpack its web-vault
     * folder below the work folder, which takes the downloads as well.
     *
     * @return string The unpacked web-vault folder
     *
     * @throws RuntimeException When the image cannot be pulled
     */
    public function pull(string $registry, string $workFolder): string
    {
        [$base, $repository, $tokenUrl] = self::REGISTRIES[$registry];
        $token = $this->token($tokenUrl, "{$workFolder}/token.json");
        $headers = ['Authorization' => "Bearer {$token}"];

        $manifest = $this->manifest("{$base}/v2/{$repository}/manifests/{$this->digest}", $headers, $this->digest, "{$workFolder}/manifest.json");
        if (isset($manifest->manifests)) {
            $digest = self::platformDigest($manifest);
            $manifest = $this->manifest("{$base}/v2/{$repository}/manifests/{$digest}", $headers, $digest, "{$workFolder}/manifest.json");
        }
        if (! is_array($manifest->layers ?? null) || $manifest->layers === []) {
            throw new RuntimeException('The image has no layers.');
        }

        $root = "{$workFolder}/root";
        foreach (array_values($manifest->layers) as $index => $layer) {
            $type = $layer instanceof stdClass ? $layer->mediaType ?? null : null;
            $digest = $layer instanceof stdClass ? $layer->digest ?? null : null;
            if (! in_array($type, self::LAYER_TYPES, true) || ! is_string($digest) || preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
                throw new RuntimeException('The image has a layer of a type that cannot be unpacked: '.(is_string($type) ? $type : 'none').'.');
            }
            $file = "{$workFolder}/layer-{$index}.tar.gz";
            $this->fetch("{$base}/v2/{$repository}/blobs/{$digest}", $headers, $file, 'a layer');
            self::verify($file, $digest);
            self::unpack($file, $root);
            unlink($file);
        }

        if (! is_file("{$root}/web-vault/index.html")) {
            throw new RuntimeException('The image holds no web vault.');
        }

        return "{$root}/web-vault";
    }

    /**
     * An anonymous token for pulling (the token authentication of the registry API).
     */
    private function token(string $url, string $file): string
    {
        $this->fetch($url, [], $file, 'a token');
        $response = json_decode((string) file_get_contents($file));
        $token = $response instanceof stdClass ? $response->token ?? $response->access_token ?? null : null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('The registry handed out no token.');
        }

        return $token;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function manifest(string $url, array $headers, string $digest, string $file): stdClass
    {
        $this->fetch($url, [...$headers, 'Accept' => implode(', ', self::MANIFEST_TYPES)], $file, "the manifest {$digest}");
        self::verify($file, $digest);
        $manifest = json_decode((string) file_get_contents($file));
        if (! $manifest instanceof stdClass) {
            throw new RuntimeException("The manifest {$digest} is not JSON.");
        }

        return $manifest;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function fetch(string $url, array $headers, string $file, string $what): void
    {
        $status = $this->downloader->download($url, $headers, $file);
        if ($status !== 200) {
            throw new RuntimeException("The registry answered {$status} when asked for {$what}.");
        }
    }

    /**
     * The image for linux/amd64 in an image index.
     */
    private static function platformDigest(stdClass $index): string
    {
        foreach (is_array($index->manifests) ? $index->manifests : [] as $entry) {
            $platform = $entry instanceof stdClass ? $entry->platform ?? null : null;
            if ($platform instanceof stdClass && ($platform->os ?? null) === 'linux' && ($platform->architecture ?? null) === 'amd64'
                && is_string($entry->digest ?? null) && preg_match('/^sha256:[0-9a-f]{64}$/', $entry->digest) === 1) {
                return $entry->digest;
            }
        }

        throw new RuntimeException('The image has no variant for linux/amd64.');
    }

    private static function verify(string $file, string $digest): void
    {
        if (! hash_equals(substr($digest, strlen('sha256:')), (string) hash_file('sha256', $file))) {
            throw new RuntimeException("The download does not match its digest {$digest}.");
        }
    }

    /**
     * Unpack what a layer holds below web-vault/ over the previous layers.
     * Whiteouts (files deleted by a layer) are refused; the image never had them.
     */
    private static function unpack(string $file, string $root): void
    {
        try {
            $archive = new PharData($file);
            // Phar names the entries below the real path of the archive.
            $prefix = 'phar://'.(realpath($file) ?: $file).'/';
            $entries = [];
            foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if (! $entry instanceof PharFileInfo) {
                    continue;
                }
                $name = substr($entry->getPathname(), strlen($prefix));
                if ($name !== 'web-vault' && ! str_starts_with($name, 'web-vault/')) {
                    continue;
                }
                if (str_starts_with(basename($name), '.wh.')) {
                    throw new RuntimeException("The image deletes {$name} in a layer, which cannot be unpacked here.");
                }
                // Folders come with their files; an archive need not list them.
                if (! $entry->isDir()) {
                    $entries[] = $name;
                }
            }
            if ($entries !== []) {
                $archive->extractTo($root, $entries, true);
            }
        } catch (UnexpectedValueException|PharException $exception) {
            throw new RuntimeException('A layer of the image cannot be unpacked: '.$exception->getMessage(), previous: $exception);
        }
    }
}
