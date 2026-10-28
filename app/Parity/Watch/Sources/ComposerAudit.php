<?php

namespace App\Parity\Watch\Sources;

use App\Parity\Matrix;
use App\Parity\Watch\Finding;
use App\Parity\Watch\Source;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Advisories and abandoned packages in composer.lock, as `composer audit`
 * finds them in Packagist's database (GitHub's advisories and FriendsOfPHP's).
 */
final readonly class ComposerAudit implements Source
{
    public function __construct(
        private string $basePath,
    ) {}

    public function name(): string
    {
        return 'composer-audit';
    }

    public function findings(): array
    {
        $result = Process::path($this->basePath)->timeout(300)
            ->run(['composer', 'audit', '--locked', '--format=json', '--no-interaction']);
        $audit = json_decode($result->output(), true);

        // The exit code is a bit mask of what was found: 1 advisories, 2 abandoned packages.
        if (! is_array($audit) || ($result->exitCode() ?? 255) > 3) {
            throw new RuntimeException('composer audit failed: '.trim($result->errorOutput()));
        }

        $locked = $this->lockedVersions();
        $findings = [];

        foreach (is_array($audit['advisories'] ?? null) ? $audit['advisories'] : [] as $package => $advisories) {
            foreach (is_array($advisories) ? $advisories : [] as $advisory) {
                if (is_array($advisory)) {
                    $findings[] = $this->advisory((string) $package, $locked[$package] ?? '?', $advisory);
                }
            }
        }

        foreach (is_array($audit['abandoned'] ?? null) ? $audit['abandoned'] : [] as $package => $replacement) {
            $findings[] = new Finding(
                key: "composer-audit:abandoned:{$package}",
                title: "{$package} is abandoned",
                body: sprintf(
                    "`composer audit` reports that `%s` is abandoned%s. Abandoned packages get no security fixes; replace it or the package that needs it.\n\nThis issue closes itself once `composer.lock` no longer has it.",
                    $package,
                    is_string($replacement) && $replacement !== '' ? "; its authors suggest `{$replacement}`" : '',
                ),
                labels: ['dependencies'],
                resolvable: true,
            );
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $advisory
     */
    private function advisory(string $package, string $version, array $advisory): Finding
    {
        $id = Matrix::string($advisory['advisoryId'] ?? '');
        $cve = Matrix::string($advisory['cve'] ?? '');
        $title = Matrix::string($advisory['title'] ?? '');
        $link = Matrix::string($advisory['link'] ?? '');

        $aliases = [];
        foreach (is_array($advisory['sources'] ?? null) ? $advisory['sources'] : [] as $source) {
            if (is_array($source)) {
                $aliases[] = sprintf('%s (%s)', Matrix::string($source['remoteId'] ?? ''), Matrix::string($source['name'] ?? ''));
            }
        }

        $rows = [
            'Package' => "`{$package}`",
            'Locked version' => "`{$version}`",
            'Affected versions' => '`'.Matrix::string($advisory['affectedVersions'] ?? '').'`',
            'Severity' => Matrix::string($advisory['severity'] ?? '') ?: 'not given',
            'Advisory' => $link === '' ? $id : "[{$id}]({$link})",
            'CVE' => $cve === '' ? 'none' : $cve,
            'Sources' => implode(', ', $aliases),
        ];

        return new Finding(
            key: "composer-audit:{$id}",
            title: mb_strimwidth(sprintf('%s in %s %s: %s', $cve !== '' ? $cve : $id, $package, $version, $title), 0, 250, '…'),
            body: "`composer audit` reports an advisory for a locked package.\n\n| | |\n| --- | --- |\n"
                .implode("\n", array_map(static fn (string $name, string $value): string => "| {$name} | {$value} |", array_keys($rows), $rows))
                ."\n\nDependabot opens a pull request when GitHub's database has the advisory. Otherwise update the package "
                ."(`composer update {$package} --with-dependencies`) and merge once the tests and the comparison with Vaultwarden pass. "
                ."Releases built from an affected `composer.lock` need a new release.\n\n"
                .'This issue closes itself once `composer.lock` has no affected version any more.',
            labels: ['security', 'dependencies'],
            resolvable: true,
        );
    }

    /**
     * @return array<string, string>
     */
    private function lockedVersions(): array
    {
        $lock = json_decode((string) @file_get_contents($this->basePath.'/composer.lock'), true);
        $versions = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock) && is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (is_array($package)) {
                    $versions[Matrix::string($package['name'] ?? '')] = Matrix::string($package['version'] ?? '');
                }
            }
        }

        return $versions;
    }
}
