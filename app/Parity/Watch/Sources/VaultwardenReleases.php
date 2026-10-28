<?php

namespace App\Parity\Watch\Sources;

use App\Parity\Matrix;
use App\Parity\Reference;
use App\Parity\Watch\Finding;
use App\Parity\Watch\GitHub;
use App\Parity\Watch\Source;
use App\Support\SemVer;
use Illuminate\Support\Str;

/**
 * Vaultwarden releases after the pinned one. Their notes name the advisories
 * a release fixes weeks before Vaultwarden publishes them, which makes a
 * release the earliest sign of a security fix.
 */
final readonly class VaultwardenReleases implements Source
{
    public function __construct(
        private GitHub $github,
        private Reference $reference,
    ) {}

    public function name(): string
    {
        return 'vaultwarden-release';
    }

    public function findings(): array
    {
        $pinned = SemVer::parse($this->reference->release);
        $repository = Str::after($this->reference->webUrl(), 'https://github.com/');
        $findings = [];

        foreach ($this->github->all("/repos/{$repository}/releases") as $release) {
            $tag = Matrix::string($release['tag_name'] ?? '');
            $version = SemVer::parse(ltrim($tag, 'v'));
            if (($release['draft'] ?? false) === true || $version === null || $pinned === null || SemVer::compare($version, $pinned) <= 0) {
                continue;
            }

            $notes = Matrix::string($release['body'] ?? '');
            $advisories = self::advisories($notes);
            $security = $advisories !== [] || preg_match('/^#+\s*Security/mi', $notes) === 1;

            $list = implode("\n", array_map(
                fn (string $id, ?string $severity): string => sprintf('- [%s](%s/security/advisories/%s)%s', $id, $this->reference->webUrl(), $id, $severity === null ? '' : " ({$severity})"),
                array_keys($advisories), $advisories,
            ));

            $findings[] = new Finding(
                key: "vaultwarden-release:{$tag}",
                title: "Vaultwarden {$tag} is released".($security ? ' with security fixes' : ''),
                body: sprintf(
                    "Vaultwarden [%s](%s) was released on %s. The pin is at %s ([`%s`](%s/commit/%s)).\n\n%s"
                    ."- [ ] Read the release notes and the commits since the pin. The issue about Vaultwarden `main` lists them with the parity items they touch; `php artisan parity:upstream %s` does the same for this release.\n"
                    ."- [ ] Port the changes, security fixes first, each with a test.\n"
                    .'- [ ] Move the pin to the release (docs/maintenance.md). This issue closes itself afterwards.',
                    $tag, Matrix::string($release['html_url'] ?? ''), substr(Matrix::string($release['published_at'] ?? ''), 0, 10),
                    $this->reference->release, substr($this->reference->commit, 0, 8), $this->reference->webUrl(), $this->reference->commit,
                    $security ? "**Its notes name security fixes.** Port them within two working days for High and Critical, a week otherwise (docs/maintenance.md).\n\n"
                        .($list === '' ? '' : "The advisories stay private until Vaultwarden publishes them, usually weeks later:\n\n{$list}\n\n") : '',
                    $tag,
                ),
                labels: $security ? ['upstream', 'upstream-security'] : ['upstream'],
                resolvable: true,
            );
        }

        return $findings;
    }

    /**
     * The advisories release notes name, with the severity given on the same line.
     *
     * @return array<string, ?string>
     */
    public static function advisories(string $notes): array
    {
        $advisories = [];
        foreach (explode("\n", $notes) as $line) {
            preg_match_all('/GHSA(?:-[23456789cfghjmpqrvwx]{4}){3}/', $line, $ids);
            $severity = preg_match('/\b(Critical|High|Medium|Moderate|Low)\b/i', $line, $match) === 1 ? ucfirst(strtolower($match[1])) : null;
            foreach ($ids[0] as $id) {
                $advisories[$id] ??= $severity;
            }
        }

        return $advisories;
    }
}
