<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

use Eleph\WordPress\Sql\EdgePlacement;
use Eleph\WordPress\Sql\Index;
use Eleph\WordPress\Sql\TableSchema;

/**
 * The physical schema, compiled.
 *
 * The adaptor needs table definitions, a field-to-column map and where every edge
 * lives. All three are derived from the IR, and the IR is a build-time artefact — so
 * they are worked out once and written down, exactly as the GraphQL surface is.
 *
 * Deriving them per request would mean shipping the schema compiler to production and
 * running it before answering anything.
 */
final readonly class StorageManifest
{
    /**
     * @param array<string, TableSchema>   $tables     Keyed by entity name.
     * @param array<string, EdgePlacement> $placements Keyed by "Entity.edge".
     * @param array<string, array<string, string>> $columns Entity => field => column.
     */
    public function __construct(
        public array $tables,
        public array $placements = [],
        public array $columns = [],
    ) {
    }

    /**
     * The same schema, in an installation whose tables are prefixed.
     *
     * Not baked in at build time: a WordPress install can use any prefix, and multisite
     * uses a different one per site. So the manifest holds the names the spec produced
     * and the prefix arrives from `$wpdb` at boot.
     */
    public function withPrefix(string $prefix): self
    {
        if ('' === $prefix) {
            return $this;
        }

        $tables = [];

        foreach ($this->tables as $entity => $table) {
            $indexes = [];

            foreach ($table->indexes as $index) {
                // Index names embed the table name, so they move with it.
                $renamed = new Index($prefix . $index->name, $index->columns, $index->unique);
                $indexes[$renamed->name] = $renamed;
            }

            $tables[$entity] = new TableSchema(
                $prefix . $table->name,
                $table->columns,
                $indexes,
                $table->primaryKey,
            );
        }

        $placements = [];

        foreach ($this->placements as $key => $placement) {
            $placements[$key] = new EdgePlacement(
                $placement->entity,
                $placement->edge,
                $placement->target,
                $placement->relation,
                $prefix . $placement->table,
                $placement->localColumn,
                $placement->targetColumn,
                $prefix . $placement->targetTable,
            );
        }

        return new self($tables, $placements, $this->columns);
    }
}
