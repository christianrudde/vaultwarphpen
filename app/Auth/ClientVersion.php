<?php

namespace App\Auth;

use Illuminate\Http\Request;

/**
 * The Bitwarden-Client-Version header as a semantic version (auth::ClientVersion).
 */
final readonly class ClientVersion
{
    private const string PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        .'(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?'
        .'(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';

    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $preRelease,
    ) {}

    /**
     * As an Option<ClientVersion> guard: null without a valid header.
     */
    public static function fromRequest(Request $request): ?self
    {
        $header = $request->headers->get('Bitwarden-Client-Version');
        if ($header === null || ! preg_match(self::PATTERN, $header, $match)) {
            return null;
        }

        return new self((int) $match[1], (int) $match[2], (int) $match[3], ($match[4] ?? '') === '' ? null : $match[4]);
    }

    /**
     * semver's `>=major.minor.patch`, under which pre-releases never match.
     */
    public function isAtLeast(int $major, int $minor, int $patch): bool
    {
        return $this->preRelease === null && [$this->major, $this->minor, $this->patch] >= [$major, $minor, $patch];
    }
}
