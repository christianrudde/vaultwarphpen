<?php

namespace App\Database;

final class SqlStatements
{
    /**
     * Split an SQL script into single statements.
     *
     * Semicolons inside quoted strings or identifiers do not end a statement,
     * and comments are dropped.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $current .= ' ';

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $i;

                while (true) {
                    $end = strpos($sql, $char, $end + 1);

                    // A doubled quote character is an escaped quote inside the literal.
                    if ($end === false || ($sql[$end + 1] ?? '') !== $char) {
                        break;
                    }
                    $end++;
                }

                $end = $end === false ? $length - 1 : $end;
                $current .= substr($sql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($char === ';') {
                $statements[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $statements[] = $current;

        return array_values(array_filter(
            array_map(trim(...), $statements),
            static fn (string $statement): bool => $statement !== '',
        ));
    }
}
