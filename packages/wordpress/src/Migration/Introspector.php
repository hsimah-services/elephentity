<?php

declare(strict_types=1);

namespace Eleph\WordPress\Migration;

use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\Index;
use Eleph\WordPress\Sql\TableSchema;

/**
 * Reads a live table back into the same shape the spec compiles to.
 *
 * The planner diffs two TableSchemas, so the database has to be describable as one.
 * MariaDB says it in two dialects — SHOW COLUMNS for the columns and SHOW INDEX for
 * the keys — and neither matches the vocabulary the compiler emits, so the translation
 * lives here rather than in the planner, which should not learn about either.
 *
 * Types are normalised to upper case with the display widths MariaDB adds stripped
 * from integers. `BIGINT UNSIGNED` comes back as `bigint(20) unsigned`, and comparing
 * those literally would report every integer column in the database as changed — a
 * refusal on every table, every boot, with nothing actually wrong.
 */
final readonly class Introspector
{
    /**
     * The table as it currently stands, or null when it does not exist.
     */
    public function inspect(Database $database, string $table): ?TableSchema
    {
        $described = $database->describeTable($table);

        if ([] === $described) {
            return null;
        }

        $columns = [];
        $primary = '';

        foreach ($described as $row) {
            $name = $this->string($row, 'Field');

            if ('' === $name) {
                continue;
            }

            if ('PRI' === $this->string($row, 'Key')) {
                $primary = $name;
            }

            $default = $row['Default'] ?? null;

            $columns[$name] = new Column(
                $name,
                $this->normalise($this->string($row, 'Type')),
                'YES' === $this->string($row, 'Null'),
                str_contains($this->string($row, 'Extra'), 'auto_increment'),
                null === $default ? null : (string) $default,
            );
        }

        return new TableSchema($table, $columns, $this->indexes($database, $table), $primary);
    }

    /**
     * @return array<string, Index>
     */
    private function indexes(Database $database, string $table): array
    {
        /** @var array<string, list<string>> $columns */
        $columns = [];
        $unique = [];

        foreach ($database->describeIndexes($table) as $row) {
            $name = $this->string($row, 'Key_name');

            // The primary key is not an index the spec declares, so it is not one the
            // planner should ever be asked to add or drop.
            if ('' === $name || 'PRIMARY' === $name) {
                continue;
            }

            $columns[$name][] = $this->string($row, 'Column_name');
            $unique[$name] = '0' === (string) ($row['Non_unique'] ?? '1');
        }

        $indexes = [];

        foreach ($columns as $name => $members) {
            $indexes[$name] = new Index($name, $members, $unique[$name] ?? false);
        }

        return $indexes;
    }

    /**
     * MariaDB's spelling, in the compiler's.
     */
    private function normalise(string $type): string
    {
        // Display widths carry no meaning on an integer and MariaDB adds them whether
        // or not anyone asked, so `bigint(20) unsigned` and `BIGINT UNSIGNED` are one
        // type. TINYINT is left alone: `TINYINT(1)` is how the compiler spells bool
        // and is what comes back, so stripping it would invent a difference.
        $stripped = (string) preg_replace('/\b(smallint|mediumint|int|integer|bigint)\(\d+\)/i', '$1', $type);

        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $stripped)));
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
