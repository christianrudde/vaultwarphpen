<?php

namespace App\Console\Commands;

use App\Parity\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ParitySync extends Command
{
    /**
     * Files taken over unchanged from the reference: source directory,
     * target directory and the files to include.
     */
    private const array MAPPINGS = [
        ['migrations/sqlite', 'database/vaultwarden/sqlite', '#/up\.sql$#'],
        ['migrations/mysql', 'database/vaultwarden/mysql', '#/up\.sql$#'],
        ['migrations/postgresql', 'database/vaultwarden/postgresql', '#/up\.sql$#'],
        ['src/static/templates', 'resources/vaultwarden/templates', '#\.hbs$#'],
    ];

    /**
     * @var string
     */
    protected $signature = 'parity:sync';

    /**
     * @var string
     */
    protected $description = 'Copy migrations and templates from the reference checkout';

    public function handle(): int
    {
        $reference = Reference::load(base_path());
        $reference->assertCheckedOut();

        foreach (self::MAPPINGS as [$source, $target, $pattern]) {
            $sourceDir = $reference->checkout.'/'.$source;
            $targetDir = base_path($target);
            $changes = ['added' => 0, 'changed' => 0, 'removed' => 0];
            $expected = [];

            foreach (File::allFiles($sourceDir) as $file) {
                $relative = $file->getRelativePathname();
                if (! preg_match($pattern, '/'.$relative)) {
                    continue;
                }

                $expected[$relative] = true;
                $destination = $targetDir.'/'.$relative;
                $contents = $file->getContents();

                if (! File::exists($destination)) {
                    $changes['added']++;
                } elseif (File::get($destination) !== $contents) {
                    $changes['changed']++;
                } else {
                    continue;
                }

                File::ensureDirectoryExists(dirname($destination));
                File::put($destination, $contents);
            }

            foreach (File::isDirectory($targetDir) ? File::allFiles($targetDir) : [] as $file) {
                if (! isset($expected[$file->getRelativePathname()])) {
                    File::delete($file->getPathname());
                    $changes['removed']++;
                }
            }

            $this->components->twoColumnDetail(
                "{$source} → {$target}",
                "{$changes['added']} added, {$changes['changed']} changed, {$changes['removed']} removed",
            );
        }

        return self::SUCCESS;
    }
}
