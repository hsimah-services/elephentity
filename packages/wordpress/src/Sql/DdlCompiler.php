<?php

declare(strict_types=1);

namespace Eleph\WordPress\Sql;

/**
 * Turns a table schema into MariaDB DDL.
 *
 * We emit our own DDL rather than handing definitions to dbDelta(). dbDelta is the
 * WordPress-standard tool and it is a parser of formatted SQL strings with a long list
 * of undocumented formatting requirements; it also silently ignores anything it does
 * not understand. Owning the statements is more predictable, and it is a prerequisite
 * for the migration planner refusing changes it cannot make safely.
 *
 * No foreign key constraints. That is the deliberate consequence of leaving dbDelta
 * behind only partway: referential integrity is enforced by the framework, where the
 * errors are better and actions still run. Revisit when deletion is built properly.
 */
final readonly class DdlCompiler
{
    private const CHARSET = 'utf8mb4';

    private const COLLATION = 'utf8mb4_unicode_ci';

    public function createTable(TableSchema $table): string
    {
        $lines = [];

        foreach ($table->columns as $column) {
            $lines[] = '  ' . $column->definition();
        }

        if ('' !== $table->primaryKey) {
            $lines[] = sprintf('  PRIMARY KEY (`%s`)', $table->primaryKey);
        }

        foreach ($table->indexes as $index) {
            $lines[] = '  ' . $index->definition();
        }

        return sprintf(
            "CREATE TABLE `%s` (\n%s\n) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s ROW_FORMAT=DYNAMIC",
            $table->name,
            implode(",\n", $lines),
            self::CHARSET,
            self::COLLATION,
        );
    }

    public function addColumn(string $table, Column $column): string
    {
        return sprintf('ALTER TABLE `%s` ADD COLUMN %s', $table, $column->definition());
    }

    public function modifyColumn(string $table, Column $column): string
    {
        return sprintf('ALTER TABLE `%s` MODIFY COLUMN %s', $table, $column->definition());
    }

    public function addIndex(string $table, Index $index): string
    {
        return sprintf('ALTER TABLE `%s` ADD %s', $table, $index->definition());
    }

    public function dropIndex(string $table, string $index): string
    {
        return sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index);
    }
}
