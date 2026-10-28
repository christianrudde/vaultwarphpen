<?php

namespace App\Parity\Watch\Sources;

use App\Parity\Reference;
use App\Parity\Upstream\Commit;
use App\Parity\Upstream\Diff;
use App\Parity\Upstream\Report;
use App\Parity\Watch\Finding;
use App\Parity\Watch\Source;

/**
 * The commits on Vaultwarden's main branch since the pin, in one issue that
 * keeps itself up to date and closes once the pin has caught up.
 */
final readonly class VaultwardenMain implements Source
{
    public function __construct(
        private Reference $reference,
        private Report $report,
    ) {}

    public function name(): string
    {
        return 'vaultwarden-main';
    }

    public function findings(): array
    {
        $this->reference->assertCheckedOut();
        $diff = new Diff($this->reference->checkout);
        $pin = $this->reference->commit;
        $head = $diff->fetch('main', $pin);
        $commits = $diff->commits($pin, $head);

        if ($commits === []) {
            return [];
        }

        $security = count(array_filter($commits, static fn (Commit $commit): bool => $commit->looksLikeSecurityFix()));

        return [new Finding(
            key: 'vaultwarden-main:'.substr($pin, 0, 8),
            title: sprintf('Vaultwarden main is %s ahead of the pin', count($commits) === 1 ? '1 commit' : count($commits).' commits')
                .($security === 0 ? '' : " ({$security} possibly security-related)"),
            body: ($diff->isAncestor($pin, $head) ? '' : "**The pinned commit is not on `main` any more.** The list shows what `main` has and the pin has not.\n\n")
                .$this->report->render('`main`', $head, $commits, $diff->changes($pin, $head), $diff->show($head, 'docker/DockerSettings.yaml'))
                ."\n\nThis issue updates itself and closes once the pin has caught up with `main`. "
                .'Move the pin with each Vaultwarden release, sooner for security fixes (docs/maintenance.md).',
            labels: ['upstream'],
            resolvable: true,
        )];
    }
}
