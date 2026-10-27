<?php

namespace Tests\Concerns;

use App\Database\VaultwardenMigrator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A migrated Vaultwarden database as default connection: in-memory SQLite,
 * and MySQL or PostgreSQL when VW_TEST_MYSQL_DATABASE or VW_TEST_PGSQL_DATABASE
 * names an empty database that may be wiped.
 */
trait UsesVaultwardenDatabase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];

        foreach (['mysql', 'pgsql'] as $driver) {
            if (getenv('VW_TEST_'.strtoupper($driver).'_DATABASE')) {
                yield $driver => [$driver];
            }
        }
    }

    protected function useDatabase(string $driver): Connection
    {
        $database = getenv('VW_TEST_'.strtoupper($driver).'_DATABASE');
        if ($driver !== 'sqlite') {
            config(["database.connections.{$driver}.database" => $database]);
        }
        config(['database.default' => $driver]);

        $connection = DB::connection($driver);
        $this->assertInstanceOf(Connection::class, $connection);

        if ($driver !== 'sqlite') {
            // Never wipe anything but the database named for these tests.
            $this->assertSame($database, $connection->getDatabaseName());
            Schema::connection($driver)->dropAllTables();
        }

        $this->app->make(VaultwardenMigrator::class)->migrate($connection);

        return $connection;
    }
}
