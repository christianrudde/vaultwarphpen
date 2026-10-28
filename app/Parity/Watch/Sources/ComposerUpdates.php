<?php

namespace App\Parity\Watch\Sources;

use App\Parity\Matrix;
use App\Parity\Watch\Finding;
use App\Parity\Watch\Source;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Minor and major updates of the packages composer.json names. Dependabot
 * proposes patches; these wait for a maintainer, so one issue lists them.
 */
final readonly class ComposerUpdates implements Source
{
    public function __construct(
        private string $basePath,
    ) {}

    public function name(): string
    {
        return 'composer-updates';
    }

    public function findings(): array
    {
        $result = Process::path($this->basePath)->timeout(300)
            ->run(['composer', 'outdated', '--locked', '--direct', '--format=json', '--no-interaction']);
        $outdated = json_decode($result->output(), true);

        if (! $result->successful() || ! is_array($outdated)) {
            throw new RuntimeException('composer outdated failed: '.trim($result->errorOutput()));
        }

        $rows = [];
        foreach (is_array($outdated['locked'] ?? null) ? $outdated['locked'] : [] as $package) {
            if (! is_array($package)) {
                continue;
            }

            $version = Matrix::string($package['version'] ?? '');
            $latest = Matrix::string($package['latest'] ?? '');
            $kind = self::kind($version, $latest);
            if ($kind !== null) {
                $rows[] = sprintf('| `%s` | %s | %s | %s |', Matrix::string($package['name'] ?? ''), $version, $latest, $kind);
            }
        }

        if ($rows === []) {
            return [];
        }

        return [new Finding(
            key: 'composer-updates:direct',
            title: count($rows) === 1 ? 'Composer: 1 minor or major update is available' : sprintf('Composer: %d minor or major updates are available', count($rows)),
            body: "Dependabot proposes patches and security fixes by itself. Minor and major updates wait for a maintainer:\n\n"
                ."| Package | Locked | Latest | Update |\n| --- | --- | --- | --- |\n".implode("\n", $rows)
                ."\n\nUpdate them in a branch (`composer update <package>`, for a major update after changing `composer.json`), "
                .'run Pint, PHPStan, the tests and the comparison with Vaultwarden, and update NOTICE.md when packages come or go. '
                .'This issue updates itself and closes once nothing is left.',
            labels: ['dependencies'],
            resolvable: true,
        )];
    }

    /**
     * "minor" or "major", or null for patches and versions that are not numbered.
     */
    public static function kind(string $version, string $latest): ?string
    {
        if (preg_match('/^v?(\d+)\.(\d+)/', $version, $from) !== 1 || preg_match('/^v?(\d+)\.(\d+)/', $latest, $to) !== 1) {
            return null;
        }

        if ($from[1] !== $to[1]) {
            return 'major';
        }

        return $from[2] !== $to[2] ? 'minor' : null;
    }
}
