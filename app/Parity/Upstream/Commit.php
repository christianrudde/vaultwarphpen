<?php

namespace App\Parity\Upstream;

/**
 * An upstream commit: its hash, the first line of its message and what it
 * changes. Authors are left out on purpose; the reports never name people.
 */
final readonly class Commit
{
    /**
     * Words that often mark a security fix in a commit title. Vaultwarden's
     * fixes land with plain titles and their advisories weeks later, so this
     * is only a hint for the order of review.
     */
    private const string SECURITY_HINTS = '/security|vulnerab|advisory|GHSA-|CVE-|bypass|escalat|leak|disclos|expos|ssrf|csrf|xss|idor|inject|travers|unauthori[sz]|authori[sz]|permission|privilege|access|revok|quota|enforc|brute|rate.?limit|2fa|two.?factor|totp|webauthn|token|password|secret|crypt|sanitiz|validat|panic|denial|\bdos\b/i';

    public function __construct(
        public string $sha,
        public string $subject,
        public Changes $changes,
    ) {}

    /**
     * The pull request a squash merge names at the end of its title: "Title (#7450)".
     */
    public function pullRequest(): ?int
    {
        return preg_match('/\(#(\d+)\)\s*$/', $this->subject, $match) === 1 ? (int) $match[1] : null;
    }

    public function looksLikeSecurityFix(): bool
    {
        return preg_match(self::SECURITY_HINTS, $this->subject) === 1;
    }
}
