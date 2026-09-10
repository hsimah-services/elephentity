<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

use Eleph\WordPress\Sql\TableSchema;

/**
 * Writes the storage manifest out as PHP that rebuilds it.
 *
 * Constructor calls rather than nested arrays, for the same reasons as the GraphQL
 * manifest: the file type-checks like any other code, and its diff reads as a
 * description of the schema — which is what a reviewer wants when a spec changes.
 */
final readonly class StorageManifestExporter
{
    public function export(StorageManifest $manifest): string
    {
        return sprintf(
            <<<'PHP'
                namespace %s;

                use Eleph\WordPress\Sql\Column;
                use Eleph\WordPress\Sql\EdgePlacement;
                use Eleph\WordPress\Sql\Index;
                use Eleph\WordPress\Sql\TableSchema;
                use Eleph\WordPress\Taxonomy\TaxonomyPlacement;
                use Eleph\Runtime\Storage\RelationKind;

                /**
                 * The compiled physical schema.
                 *
                 * Loaded at boot and handed to the adaptor as-is. Nothing here is worked out
                 * per request; the spec decided all of it.
                 */
                return new StorageManifest(
                    tables: [
                %s
                    ],
                    placements: [
                %s
                    ],
                    columns: [
                %s
                    ],
                    joinTables: [
                %s
                    ],
                    taxonomies: [
                %s
                    ],
                    taxonomyPlacements: [
                %s
                    ],
                );

                PHP,
            __NAMESPACE__,
            $this->tables($manifest->tables),
            $this->placements($manifest),
            $this->columns($manifest),
            $this->tables($manifest->joinTables),
            $this->taxonomies($manifest),
            $this->taxonomyPlacements($manifest),
        );
    }

    private function taxonomies(StorageManifest $manifest): string
    {
        $lines = [];

        foreach ($manifest->taxonomies as $entity => $slug) {
            $lines[] = sprintf('        %s => %s,', var_export($entity, true), var_export($slug, true));
        }

        return implode("\n", $lines);
    }

    private function taxonomyPlacements(StorageManifest $manifest): string
    {
        $lines = [];

        foreach ($manifest->taxonomyPlacements as $key => $placement) {
            $lines[] = sprintf(
                "        %s => new TaxonomyPlacement(\n            %s,\n            %s,\n            %s,\n            %s,\n        ),",
                var_export($key, true),
                var_export($placement->entity, true),
                var_export($placement->edge, true),
                var_export($placement->target, true),
                var_export($placement->taxonomy, true),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, TableSchema> $tables
     */
    private function tables(array $tables): string
    {
        $lines = [];

        foreach ($tables as $entity => $table) {
            $lines[] = sprintf(
                "        %s => new TableSchema(\n            %s,\n            [\n%s\n            ],\n            [\n%s\n            ],\n            %s,\n        ),",
                var_export($entity, true),
                var_export($table->name, true),
                $this->columnsOf($table),
                $this->indexesOf($table),
                var_export($table->primaryKey, true),
            );
        }

        return implode("\n", $lines);
    }

    private function columnsOf(TableSchema $table): string
    {
        $lines = [];

        foreach ($table->columns as $column) {
            $lines[] = sprintf(
                '                %s => new Column(%s, %s, %s, %s, %s),',
                var_export($column->name, true),
                var_export($column->name, true),
                var_export($column->type, true),
                $column->nullable ? 'true' : 'false',
                $column->autoIncrement ? 'true' : 'false',
                null === $column->default ? 'null' : var_export($column->default, true),
            );
        }

        return implode("\n", $lines);
    }

    private function indexesOf(TableSchema $table): string
    {
        $lines = [];

        foreach ($table->indexes as $index) {
            $lines[] = sprintf(
                '                %s => new Index(%s, [%s], %s),',
                var_export($index->name, true),
                var_export($index->name, true),
                implode(', ', array_map(static fn (string $c): string => var_export($c, true), $index->columns)),
                $index->unique ? 'true' : 'false',
            );
        }

        return implode("\n", $lines);
    }

    private function placements(StorageManifest $manifest): string
    {
        $lines = [];

        foreach ($manifest->placements as $key => $placement) {
            $lines[] = sprintf(
                "        %s => new EdgePlacement(\n            %s,\n            %s,\n            %s,\n            RelationKind::%s,\n            %s,\n            %s,\n            %s,\n            %s,\n        ),",
                var_export($key, true),
                var_export($placement->entity, true),
                var_export($placement->edge, true),
                var_export($placement->target, true),
                $placement->relation->name,
                var_export($placement->table, true),
                var_export($placement->localColumn, true),
                null === $placement->targetColumn ? 'null' : var_export($placement->targetColumn, true),
                var_export($placement->targetTable, true),
            );
        }

        return implode("\n", $lines);
    }

    private function columns(StorageManifest $manifest): string
    {
        $lines = [];

        foreach ($manifest->columns as $entity => $fields) {
            $pairs = [];

            foreach ($fields as $field => $column) {
                $pairs[] = sprintf('%s => %s', var_export($field, true), var_export($column, true));
            }

            $lines[] = sprintf('        %s => [%s],', var_export($entity, true), implode(', ', $pairs));
        }

        return implode("\n", $lines);
    }
}
