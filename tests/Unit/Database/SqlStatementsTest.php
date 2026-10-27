<?php

namespace Tests\Unit\Database;

use App\Database\SqlStatements;
use PHPUnit\Framework\TestCase;

class SqlStatementsTest extends TestCase
{
    public function test_it_splits_at_semicolons_and_drops_comments(): void
    {
        $sql = <<<'SQL'
            -- Your SQL goes here
            CREATE TABLE a (id TEXT NOT NULL PRIMARY KEY); /* block; comment */
            ALTER TABLE a ADD COLUMN b TEXT;

            -- trailing comment only
            SQL;

        $this->assertSame([
            'CREATE TABLE a (id TEXT NOT NULL PRIMARY KEY)',
            'ALTER TABLE a ADD COLUMN b TEXT',
        ], SqlStatements::split($sql));
    }

    public function test_semicolons_and_dashes_inside_quotes_are_kept(): void
    {
        $sql = "INSERT INTO a VALUES ('x;y', 'it''s -- fine'); UPDATE \"a;b\" SET `c;d` = 1";

        $this->assertSame([
            "INSERT INTO a VALUES ('x;y', 'it''s -- fine')",
            'UPDATE "a;b" SET `c;d` = 1',
        ], SqlStatements::split($sql));
    }
}
