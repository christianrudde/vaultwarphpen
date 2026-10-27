<?php

namespace Tests\Feature\Database;

use App\Database\VaultwardenMigrator;
use App\Parity\Matrix;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Attributes\Parity;
use Tests\TestCase;

/**
 * Runs against in-memory SQLite, and additionally against MySQL or
 * PostgreSQL when VW_TEST_MYSQL_DATABASE or VW_TEST_PGSQL_DATABASE names an
 * empty database that may be wiped (connection settings come from .env).
 */
#[Parity('table:*')]
class VaultwardenMigratorTest extends TestCase
{
    /**
     * Column types of src/db/schema.rs and the database types they may map to.
     */
    private const array TYPES = [
        'Text' => ['text', 'varchar', 'char', 'mediumtext', 'longtext', 'bpchar'],
        'Integer' => ['integer', 'int', 'int4', 'smallint', 'int2'],
        'BigInt' => ['bigint', 'int8', 'integer'],
        'Bool' => ['boolean', 'bool', 'tinyint'],
        'Timestamp' => ['datetime', 'timestamp'],
        'Binary' => ['blob', 'bytea', 'longblob', 'binary', 'varbinary'],
    ];

    /**
     * Where Vaultwarden's own migrations and src/db/schema.rs disagree. The
     * migrations are what real databases contain, so they win.
     */
    private const array KNOWN_SCHEMA_RS_DIFFERENCES = [
        // Never read by Vaultwarden; the database fills in the default.
        'sso_users.created_at: not in schema.rs',
        // Created without NOT NULL; Vaultwarden always sets it.
        'emergency_access.grantor_uuid: nullable, schema.rs says not null',
    ];

    /**
     * Differences that only one database type has.
     */
    private const array KNOWN_DRIVER_DIFFERENCES = [
        'mysql' => [
            // `CHANGE COLUMN key akey TEXT` (2019-05-26) dropped NOT NULL on MySQL only.
            'users.akey: nullable, schema.rs says not null',
            'users_organizations.akey: nullable, schema.rs says not null',
        ],
    ];

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

    #[DataProvider('drivers')]
    public function test_all_migrations_are_applied_and_booked_like_diesel(string $driver): void
    {
        $connection = $this->connection($driver);
        $migrator = $this->app->make(VaultwardenMigrator::class);
        $migrations = $migrator->migrations($migrator->dialect($connection));

        $this->assertSame(array_column($migrations, 'name'), $migrator->migrate($connection));

        $versions = array_map(
            static fn (mixed $row): string => is_object($row) && property_exists($row, 'version') && is_string($row->version) ? $row->version : '',
            $connection->select('SELECT version FROM '.VaultwardenMigrator::TABLE.' ORDER BY version'),
        );
        $this->assertSame(array_column($migrations, 'version'), $versions);
        $this->assertContains('20180114171611', $versions);
    }

    #[DataProvider('drivers')]
    public function test_running_again_applies_nothing(string $driver): void
    {
        $connection = $this->connection($driver);
        $migrator = $this->app->make(VaultwardenMigrator::class);

        $migrator->migrate($connection);

        $this->assertSame([], $migrator->migrate($connection));
    }

    #[DataProvider('drivers')]
    public function test_schema_matches_the_reference_schema(string $driver): void
    {
        $connection = $this->connection($driver);
        $this->app->make(VaultwardenMigrator::class)->migrate($connection);
        $schema = Schema::connection($driver);

        $tables = array_filter(
            Matrix::load(base_path('parity/matrix.json'))->items,
            static fn (array $item): bool => ($item['kind'] ?? null) === 'table' && ! isset($item['upstream']),
        );
        $expectedTables = array_map(static fn (string $id): string => substr($id, strlen('table:')), array_keys($tables));

        // On MySQL, getTables() without a schema lists every database on the server.
        $actualTables = array_diff(
            array_column($schema->getTables($driver === 'mysql' ? $connection->getDatabaseName() : null), 'name'),
            [VaultwardenMigrator::TABLE],
        );
        sort($expectedTables);
        sort($actualTables);
        $this->assertSame($expectedTables, $actualTables);

        $differences = [];
        foreach ($tables as $id => $table) {
            $name = substr($id, strlen('table:'));
            $expected = is_array($table['columns'] ?? null) ? $table['columns'] : [];
            $actual = array_column($schema->getColumns($name), null, 'name');

            foreach (array_diff_key($actual, $expected) as $column => $_) {
                $differences[] = "{$name}.{$column}: not in schema.rs";
            }

            foreach ($expected as $column => $type) {
                if (! isset($actual[$column])) {
                    $differences[] = "{$name}.{$column}: missing in the database";

                    continue;
                }

                $type = Matrix::string($type);
                $nullable = str_starts_with($type, 'Nullable<');
                $base = $nullable ? substr($type, strlen('Nullable<'), -1) : $type;
                $actualType = strtolower(Matrix::string($actual[$column]['type_name']));

                if ($nullable !== (bool) $actual[$column]['nullable']) {
                    $differences[] = "{$name}.{$column}: ".($nullable ? 'not null, schema.rs says nullable' : 'nullable, schema.rs says not null');
                }
                if (! in_array($actualType, self::TYPES[$base] ?? [], true)) {
                    $differences[] = "{$name}.{$column}: type {$actualType}, schema.rs says {$base}";
                }
            }
        }

        $this->assertEqualsCanonicalizing(
            [...self::KNOWN_SCHEMA_RS_DIFFERENCES, ...self::KNOWN_DRIVER_DIFFERENCES[$driver] ?? []],
            $differences,
        );
    }

    private function connection(string $driver): Connection
    {
        $database = getenv('VW_TEST_'.strtoupper($driver).'_DATABASE');
        if ($driver !== 'sqlite') {
            config(["database.connections.{$driver}.database" => $database]);
        }

        $connection = DB::connection($driver);
        $this->assertInstanceOf(Connection::class, $connection);

        if ($driver !== 'sqlite') {
            // Never wipe anything but the database named for these tests.
            $this->assertSame($database, $connection->getDatabaseName());
            Schema::connection($driver)->dropAllTables();
        }

        return $connection;
    }
}
