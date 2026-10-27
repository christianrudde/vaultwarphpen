<?php

namespace App\Console\Commands;

use App\Parity\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ParityFetch extends Command
{
    /**
     * @var string
     */
    protected $signature = 'parity:fetch';

    /**
     * @var string
     */
    protected $description = 'Check out the pinned Vaultwarden commit into reference/vaultwarden';

    public function handle(): int
    {
        $reference = Reference::load(base_path());

        if ($reference->checkedOutCommit() === $reference->commit) {
            $this->components->info("Reference is already at {$reference->commit}.");

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($reference->checkout);
        $git = Process::path($reference->checkout)->timeout(600);

        if (! File::isDirectory($reference->checkout.'/.git')) {
            $git->run(['git', 'init', '--quiet'])->throw();
            $git->run(['git', 'remote', 'add', 'origin', $reference->repository])->throw();
        }

        $this->components->task("Fetching {$reference->commit}", function () use ($git, $reference): void {
            $git->run(['git', 'fetch', '--quiet', '--depth', '1', 'origin', $reference->commit])->throw();
            $git->run(['git', 'checkout', '--quiet', '--force', '--detach', $reference->commit])->throw();
        });

        return self::SUCCESS;
    }
}
