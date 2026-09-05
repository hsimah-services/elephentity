<?php

declare(strict_types=1);

namespace PheFr\WordPress\Migration;

use PheFr\WordPress\Sql\DdlCompiler;
use PheFr\WordPress\Sql\TableSchema;

/**
 * Diffs the database against the spec.
 *
 * Additive changes — a new table, a new nullable column, a new index — are generated
 * and applied. Everything else stops the build and asks for an explicit migration,
 * because the diff does not carry enough information to act safely:
 *
 *   - A column in the database but not the spec is either a rename or a drop. Guess
 *     wrong and the data is gone.
 *   - A changed column type may be lossy in one direction and not the other.
 *   - A new NOT NULL column with no default cannot be added to a table with rows
 *     without deciding what those rows should hold.
 *
 * Dropping an index is the one destructive-looking change that is allowed: it loses no
 * data and is trivially reversible.
 */
final readonly class MigrationPlanner
{
    public function __construct(private DdlCompiler $ddl = new DdlCompiler())
    {
    }

    /**
     * @param array<string, TableSchema> $desired
     * @param array<string, TableSchema> $current What introspection found.
     */
    public function plan(array $desired, array $current): MigrationPlan
    {
        $statements = [];
        $refusals = [];

        foreach ($desired as $name => $table) {
            $existing = $current[$name] ?? null;

            if (null === $existing) {
                $statements[] = $this->ddl->createTable($table);

                continue;
            }

            foreach ($this->alter($table, $existing) as $change) {
                if ($change instanceof Refusal) {
                    $refusals[] = $change;

                    continue;
                }

                $statements[] = $change;
            }
        }

        foreach ($current as $name => $table) {
            if (isset($desired[$name])) {
                continue;
            }

            $refusals[] = new Refusal(
                'table.unexpected',
                sprintf(
                    'Table "%s" exists but no entity maps to it. Drop it in an explicit migration if that is intended.',
                    $name,
                ),
                $name,
            );
        }

        return new MigrationPlan($statements, $refusals);
    }

    /**
     * @return list<string|Refusal>
     */
    private function alter(TableSchema $desired, TableSchema $current): array
    {
        $changes = [];

        foreach ($desired->columns as $name => $column) {
            $existing = $current->column($name);

            if (null === $existing) {
                if (!$column->nullable && null === $column->default && !$column->autoIncrement) {
                    $changes[] = new Refusal(
                        'column.notNullWithoutDefault',
                        sprintf(
                            'Column "%s" is NOT NULL with no default; existing rows have nothing to put in it. Add it in an explicit migration, or make it nullable.',
                            $name,
                        ),
                        $desired->name,
                    );

                    continue;
                }

                $changes[] = $this->ddl->addColumn($desired->name, $column);

                continue;
            }

            if ($existing->type !== $column->type || $existing->nullable !== $column->nullable) {
                $changes[] = new Refusal(
                    'column.changed',
                    sprintf(
                        'Column "%s" is %s%s but the spec wants %s%s. A type change can be lossy, so state the intent in an explicit migration.',
                        $name,
                        $existing->type,
                        $existing->nullable ? ' NULL' : ' NOT NULL',
                        $column->type,
                        $column->nullable ? ' NULL' : ' NOT NULL',
                    ),
                    $desired->name,
                );
            }
        }

        foreach ($current->columns as $name => $column) {
            if (null !== $desired->column($name)) {
                continue;
            }

            $changes[] = new Refusal(
                'column.unexpected',
                sprintf(
                    'Column "%s" exists but no field maps to it. This is either a rename or a drop, and the diff cannot tell which — say so in an explicit migration.',
                    $name,
                ),
                $desired->name,
            );
        }

        foreach ($desired->indexes as $name => $index) {
            if (!isset($current->indexes[$name])) {
                $changes[] = $this->ddl->addIndex($desired->name, $index);
            }
        }

        foreach ($current->indexes as $name => $index) {
            if (!isset($desired->indexes[$name])) {
                // Safe: an index holds no data of its own.
                $changes[] = $this->ddl->dropIndex($desired->name, $name);
            }
        }

        return $changes;
    }
}
