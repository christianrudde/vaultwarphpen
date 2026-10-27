<?php

namespace App\Database;

use Illuminate\Database\Connection;
use PDO;
use RuntimeException;

/**
 * Applies Vaultwarden's own SQL migrations and books them in Diesel's
 * migration table, so the database stays interchangeable with Vaultwarden
 * in both directions.
 */
final class VaultwardenMigrator
{
    public const string TABLE = '__diesel_schema_migrations';

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * Run all pending migrations.
     *
     * @return list<string> Names of the applied migrations
     */
    public function migrate(Connection $connection): array
    {
        $dialect = $this->dialect($connection);

        $connection->unprepared(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                version VARCHAR(50) PRIMARY KEY NOT NULL,
                run_on TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $applied = $this->appliedVersions($connection);
        $pending = array_values(array_filter(
            $this->migrations($dialect),
            static fn (array $migration): bool => ! in_array($migration['version'], $applied, true),
        ));

        if ($pending === []) {
            return [];
        }

        $this->withoutForeignKeyChecks($connection, $dialect, function () use ($connection, $dialect, $pending): void {
            foreach ($pending as $migration) {
                $run = function () use ($connection, $dialect, $migration): void {
                    foreach (SqlStatements::split($migration['sql']) as $statement) {
                        $this->execute($connection, $dialect, $statement);
                    }

                    $connection->insert('INSERT INTO '.self::TABLE.' (version) VALUES (?)', [$migration['version']]);
                };

                // MySQL commits implicitly after DDL, so a transaction cannot wrap it.
                $dialect === 'mysql' ? $run() : $connection->transaction($run);
            }
        });

        return array_column($pending, 'name');
    }

    /**
     * All migrations for a dialect, in the order Diesel applies them.
     *
     * @return list<array{name: string, version: string, sql: string}>
     */
    public function migrations(string $dialect): array
    {
        $files = glob($this->path.'/'.$dialect.'/*/up.sql') ?: [];

        $migrations = array_map(function (string $file): array {
            $name = basename(dirname($file));

            return [
                'name' => $name,
                'version' => self::version($name),
                'sql' => (string) file_get_contents($file),
            ];
        }, $files);

        usort($migrations, static fn (array $a, array $b): int => strcmp($a['version'], $b['version']));

        return $migrations;
    }

    /**
     * Diesel's version of a migration directory: the part before the first
     * underscore, without dashes ("2018-01-14-171611_create_tables" becomes
     * "20180114171611").
     */
    public static function version(string $name): string
    {
        return str_replace('-', '', explode('_', $name, 2)[0]);
    }

    /**
     * The migration directory used for a connection.
     */
    public function dialect(Connection $connection): string
    {
        return match ($connection->getDriverName()) {
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'postgresql',
            default => throw new RuntimeException("Unsupported database driver [{$connection->getDriverName()}]."),
        };
    }

    private function execute(Connection $connection, string $dialect, string $statement): void
    {
        // The statements come from the migration files, so they run as they are.
        $pdo = $connection->getPdo();

        if ($dialect !== 'mysql') {
            $pdo->exec($statement);

            return;
        }

        // Some MySQL migrations use PREPARE/EXECUTE, which needs the text
        // protocol, and EXECUTE may return rows that must be discarded before
        // the next statement can run.
        $emulate = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $result = $pdo->query($statement);
            if ($result !== false) {
                $result->closeCursor();
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        }
    }

    /**
     * @return list<string>
     */
    private function appliedVersions(Connection $connection): array
    {
        $versions = [];

        foreach ($connection->select('SELECT version FROM '.self::TABLE) as $row) {
            if (is_object($row) && isset($row->version) && is_string($row->version)) {
                $versions[] = $row->version;
            }
        }

        return $versions;
    }

    /**
     * Vaultwarden disables foreign key checks on SQLite and MySQL while migrating.
     *
     * @param  callable(): void  $callback
     */
    private function withoutForeignKeyChecks(Connection $connection, string $dialect, callable $callback): void
    {
        $statements = match ($dialect) {
            'sqlite' => ['PRAGMA foreign_keys = OFF', 'PRAGMA foreign_keys = '.$this->sqliteForeignKeys($connection)],
            'mysql' => ['SET FOREIGN_KEY_CHECKS = 0', 'SET FOREIGN_KEY_CHECKS = 1'],
            default => null,
        };

        if ($statements === null) {
            $callback();

            return;
        }

        $connection->unprepared($statements[0]);

        try {
            $callback();
        } finally {
            $connection->unprepared($statements[1]);
        }
    }

    /**
     * @return 'ON'|'OFF'
     */
    private function sqliteForeignKeys(Connection $connection): string
    {
        $row = $connection->selectOne('PRAGMA foreign_keys');

        return is_object($row) && isset($row->foreign_keys) && $row->foreign_keys ? 'ON' : 'OFF';
    }
}
