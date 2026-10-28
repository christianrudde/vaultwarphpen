<?php

namespace Tests\Unit\Database;

use App\Database\SchemaComparison;
use PHPUnit\Framework\TestCase;

class SchemaComparisonTest extends TestCase
{
    public function test_the_same_schema(): void
    {
        $this->assertTrue(SchemaComparison::between(self::snapshot(), self::snapshot())->isSame());
    }

    public function test_mariadb_spellings_are_the_same(): void
    {
        $mariadb = self::snapshot(['type' => 'bigint(20)', 'default' => "'0'"], ['type' => 'datetime', 'default' => 'current_timestamp()'], ['default' => 'NULL']);

        $this->assertTrue(SchemaComparison::between(self::snapshot(), $mariadb)->isSame());
    }

    public function test_differences_by_name(): void
    {
        $actual = self::snapshot(['type' => 'text']);
        $users = $actual['tables']['users'];
        $users['columns'] = [$users['columns'][0], $users['columns'][1], ['name' => 'nickname', 'type' => 'text', 'nullable' => true, 'default' => null, 'auto_increment' => false]];
        $users['indexes'] = [];
        $actual['tables'] = ['notes' => ['columns' => [], 'indexes' => [], 'foreign_keys' => []], 'users' => $users];

        $differences = SchemaComparison::between(self::snapshot(), $actual);

        $this->assertFalse($differences->isSame());
        $this->assertSame(['devices'], $differences->missingTables);
        $this->assertSame(['notes'], $differences->extraTables);
        $this->assertSame(['users.email'], $differences->missingColumns);
        $this->assertSame(['users.nickname'], $differences->extraColumns);
        $this->assertSame(['users.counter'], $differences->changedColumns);
        $this->assertSame(['users'], $differences->changedKeys);
    }

    /**
     * A snapshot as SchemaSnapshot::of() writes it, of MySQL 8.4, with the
     * columns counter, created_at and email of users changed as given.
     *
     * @param  array<string, mixed>  $counter
     * @param  array<string, mixed>  $createdAt
     * @param  array<string, mixed>  $email
     * @return array{migrations: list<string>, tables: array<string, array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>}>}
     */
    private static function snapshot(array $counter = [], array $createdAt = [], array $email = []): array
    {
        return ['migrations' => ['20180114171611'], 'tables' => [
            'devices' => ['columns' => [self::column('uuid', 'char(36)', '', [])], 'indexes' => [], 'foreign_keys' => []],
            'users' => [
                'columns' => [
                    self::column('counter', 'bigint', '0', $counter),
                    self::column('created_at', 'datetime', 'CURRENT_TIMESTAMP', $createdAt),
                    self::column('email', 'varchar(255)', null, $email),
                ],
                'indexes' => [['name' => 'primary', 'columns' => ['email'], 'unique' => true, 'primary' => true]],
                'foreign_keys' => [],
            ],
        ]];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function column(string $name, string $type, ?string $default, array $changes): array
    {
        return ['name' => $name, 'type' => $type, 'nullable' => $default === null, 'default' => $default, 'auto_increment' => false, ...$changes];
    }
}
