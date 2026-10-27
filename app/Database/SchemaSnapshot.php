<?php

namespace App\Database;

use Illuminate\Database\Connection;

/**
 * A comparable description of a Vaultwarden database: the migrations booked
 * in Diesel's table and every table with its columns, indexes and foreign keys.
 *
 * Details that depend on the server rather than on the migrations (sizes,
 * collations, engines, the database name) are left out, so a database our
 * migrator created compares equal to one Vaultwarden created on another server
 * of the same kind.
 */
final class SchemaSnapshot
{
    /**
     * @return array{migrations: list<string>, tables: array<string, array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>}>}
     */
    public static function of(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();

        // On MySQL, getTables() without a schema lists every database on the server.
        $names = array_column($schema->getTables($connection->getDriverName() === 'mysql' ? $connection->getDatabaseName() : null), 'name');
        sort($names);

        $tables = [];
        foreach ($names as $name) {
            $tables[$name] = [
                'columns' => array_map(static fn (array $column): array => [
                    'name' => $column['name'],
                    'type' => $column['type'],
                    'nullable' => $column['nullable'],
                    'default' => $column['default'],
                    'auto_increment' => $column['auto_increment'],
                ], $schema->getColumns($name)),
                'indexes' => self::sorted(array_map(static fn (array $index): array => [
                    'name' => $index['name'],
                    'columns' => $index['columns'],
                    'unique' => $index['unique'],
                    'primary' => $index['primary'],
                ], $schema->getIndexes($name))),
                'foreign_keys' => self::sorted(array_map(static fn (array $key): array => [
                    'name' => $key['name'],
                    'columns' => $key['columns'],
                    'foreign_table' => $key['foreign_table'],
                    'foreign_columns' => $key['foreign_columns'],
                    'on_update' => $key['on_update'],
                    'on_delete' => $key['on_delete'],
                ], $schema->getForeignKeys($name))),
            ];
        }

        $migrations = [];
        if (in_array(VaultwardenMigrator::TABLE, $names, true)) {
            foreach ($connection->select('SELECT version FROM '.VaultwardenMigrator::TABLE.' ORDER BY version') as $row) {
                $migrations[] = is_object($row) && property_exists($row, 'version') && is_string($row->version) ? $row->version : '';
            }
        }

        return ['migrations' => $migrations, 'tables' => $tables];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function json(array $snapshot): string
    {
        return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Indexes and foreign keys in a stable order; not every database lists them by name.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $items): array
    {
        $items = array_values($items);
        usort($items, static fn (array $a, array $b): int => strcmp(
            json_encode($a, JSON_THROW_ON_ERROR),
            json_encode($b, JSON_THROW_ON_ERROR),
        ));

        return $items;
    }
}
