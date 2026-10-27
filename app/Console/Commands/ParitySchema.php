<?php

namespace App\Console\Commands;

use App\Database\SchemaSnapshot;
use App\Database\VaultwardenMigrator;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\File;

class ParitySchema extends Command
{
    /**
     * @var string
     */
    protected $signature = 'parity:schema
                            {--database= : The database connection to describe}
                            {--output= : The file to write (default: parity/schema/<dialect>.json)}';

    /**
     * @var string
     */
    protected $description = 'Write a comparable snapshot of a database schema (see App\Database\SchemaSnapshot)';

    public function handle(DatabaseManager $databases, VaultwardenMigrator $migrator): int
    {
        $name = $this->option('database');
        $connection = $databases->connection(is_string($name) ? $name : null);

        $output = $this->option('output');
        $file = is_string($output) && $output !== ''
            ? $output
            : base_path('parity/schema/'.$migrator->dialect($connection).'.json');

        File::ensureDirectoryExists(dirname($file));
        File::put($file, SchemaSnapshot::json(SchemaSnapshot::of($connection)));

        $this->components->info("Schema written to {$file}.");

        return self::SUCCESS;
    }
}
