<?php

namespace App\Database;

/**
 * Where a database differs from the one Vaultwarden creates (SchemaSnapshot,
 * parity/schema/<dialect>.json), by table and column name only.
 *
 * Snapshots come from the server CI runs. Others of the same kind may spell
 * a column a little differently (MariaDB keeps integer display widths and
 * writes current_timestamp()), so details count as differences only after
 * those spellings are evened out.
 */
final readonly class SchemaComparison
{
    /**
     * @param  list<string>  $missingTables
     * @param  list<string>  $extraTables
     * @param  list<string>  $missingColumns  table.column
     * @param  list<string>  $extraColumns  table.column
     * @param  list<string>  $changedColumns  table.column, with another type, nullability or default
     * @param  list<string>  $changedKeys  Tables with other indexes or foreign keys
     */
    public function __construct(
        public array $missingTables = [],
        public array $extraTables = [],
        public array $missingColumns = [],
        public array $extraColumns = [],
        public array $changedColumns = [],
        public array $changedKeys = [],
    ) {}

    /**
     * @param  array<mixed>  $expected  A snapshot of SchemaSnapshot::of()
     * @param  array<mixed>  $actual  Another one
     */
    public static function between(array $expected, array $actual): self
    {
        $expectedTables = self::tables($expected);
        $actualTables = self::tables($actual);
        $missingColumns = $extraColumns = $changedColumns = $changedKeys = [];

        foreach (array_intersect_key($expectedTables, $actualTables) as $name => $table) {
            $expectedColumns = self::columns($table);
            $actualColumns = self::columns($actualTables[$name]);
            foreach (array_diff_key($expectedColumns, $actualColumns) as $column => $_) {
                $missingColumns[] = "{$name}.{$column}";
            }
            foreach (array_diff_key($actualColumns, $expectedColumns) as $column => $_) {
                $extraColumns[] = "{$name}.{$column}";
            }
            foreach (array_intersect_key($expectedColumns, $actualColumns) as $column => $details) {
                if ($details !== $actualColumns[$column]) {
                    $changedColumns[] = "{$name}.{$column}";
                }
            }
            if (self::keys($table) !== self::keys($actualTables[$name])) {
                $changedKeys[] = $name;
            }
        }

        return new self(
            array_keys(array_diff_key($expectedTables, $actualTables)),
            array_keys(array_diff_key($actualTables, $expectedTables)),
            $missingColumns,
            $extraColumns,
            $changedColumns,
            $changedKeys,
        );
    }

    public function isSame(): bool
    {
        return $this == new self;
    }

    /**
     * @param  array<mixed>  $snapshot
     * @return array<string, array<mixed>>
     */
    private static function tables(array $snapshot): array
    {
        $tables = [];
        foreach (is_array($snapshot['tables'] ?? null) ? $snapshot['tables'] : [] as $name => $table) {
            $tables[(string) $name] = is_array($table) ? $table : [];
        }

        return $tables;
    }

    /**
     * @param  array<mixed>  $table
     * @return array<string, array{mixed, mixed, mixed, mixed}> Name => type, nullable, default, auto increment
     */
    private static function columns(array $table): array
    {
        $columns = [];
        foreach (is_array($table['columns'] ?? null) ? $table['columns'] : [] as $column) {
            if (is_array($column) && is_string($column['name'] ?? null)) {
                $columns[$column['name']] = [self::type($column['type'] ?? null), $column['nullable'] ?? null,
                    self::default($column['default'] ?? null), $column['auto_increment'] ?? null];
            }
        }

        return $columns;
    }

    /**
     * @param  array<mixed>  $table
     */
    private static function keys(array $table): string
    {
        return (string) json_encode([$table['indexes'] ?? [], $table['foreign_keys'] ?? []]);
    }

    private static function type(mixed $type): mixed
    {
        return is_string($type) ? (string) preg_replace('/^((?:big|medium|small)?int)\(\d+\)/', '$1', strtolower($type)) : $type;
    }

    private static function default(mixed $default): mixed
    {
        if (! is_string($default)) {
            return $default;
        }
        $default = (string) preg_replace('/^current_timestamp(\(\))?$/i', 'CURRENT_TIMESTAMP', $default);

        return match (true) {
            $default === 'NULL' => null,
            preg_match("/^'([^']*)'$/", $default, $match) === 1 => $match[1],
            default => $default,
        };
    }
}
