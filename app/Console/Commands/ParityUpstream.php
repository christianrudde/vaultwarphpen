<?php

namespace App\Console\Commands;

use App\Parity\Matrix;
use App\Parity\Reference;
use App\Parity\Upstream\Counterparts;
use App\Parity\Upstream\Diff;
use App\Parity\Upstream\Report;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class ParityUpstream extends Command
{
    /**
     * @var string
     */
    protected $signature = 'parity:upstream
        {revision=main : A branch, tag or commit of the reference repository}
        {--no-fetch : Use what the checkout has, by default what the last fetch brought}';

    /**
     * @var string
     */
    protected $description = 'List what changed upstream since the pinned commit and the parity items it touches';

    public function handle(): int
    {
        $reference = Reference::load(base_path());
        $reference->assertCheckedOut();

        $revision = $this->argument('revision');
        $diff = new Diff($reference->checkout);
        $head = $this->option('no-fetch')
            ? $diff->resolve($revision) ?? $diff->resolve(Diff::REF) ?? throw new RuntimeException("The reference checkout has no {$revision}; fetch it first.")
            : $diff->fetch($revision, $reference->commit);

        if (! $diff->isAncestor($reference->commit, $head)) {
            $this->components->warn("The pinned commit is not an ancestor of {$revision}; the list shows what {$revision} has and the pin has not.");
        }

        $report = new Report($reference, new Counterparts(Matrix::load(base_path('parity/matrix.json'))->items, base_path()));
        // Raw, so that the console formatter leaves the markdown alone.
        $this->output->writeln($report->render(
            "`{$revision}`",
            $head,
            $diff->commits($reference->commit, $head),
            $diff->changes($reference->commit, $head),
            $diff->show($head, 'docker/DockerSettings.yaml'),
        ), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
