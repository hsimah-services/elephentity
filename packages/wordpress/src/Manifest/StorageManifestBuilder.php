<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

use Eleph\Schema\Ir\Schema;
use Eleph\WordPress\Sql\EdgePlanner;
use Eleph\WordPress\Sql\Naming;
use Eleph\WordPress\Sql\SchemaBuilder;
use Eleph\WordPress\Sql\TableSchema;

/**
 * Compiles the physical schema from the spec.
 *
 * Reuses the same SchemaBuilder and EdgePlanner the migration planner uses, so what
 * ships and what the database is migrated to cannot disagree.
 */
final readonly class StorageManifestBuilder
{
    public function __construct(private Naming $naming = new Naming())
    {
    }

    public function build(Schema $schema): StorageManifest
    {
        $byTable = (new SchemaBuilder($this->naming))->build($schema);

        /** @var array<string, TableSchema> $byEntity */
        $byEntity = [];
        $columns = [];
        $claimed = [];

        foreach ($schema->entities as $entity) {
            $table = $this->naming->table($entity->storage->table);
            $claimed[$table] = true;

            if (isset($byTable[$table])) {
                $byEntity[$entity->name] = $byTable[$table];
            }

            foreach ($entity->fields as $field) {
                $columns[$entity->name][$field->name] = $this->naming->column($field->name);
            }
        }

        // Whatever the builder produced that no entity claims is a join table. Keyed by
        // entity, they had nowhere to go and were dropped — so a many-to-many edge
        // compiled to a placement pointing at a table nothing would ever create.
        $joinTables = array_diff_key($byTable, $claimed);

        $taxonomies = [];

        foreach ($schema->entities as $entity) {
            if (EdgePlanner::isTaxonomy($entity)) {
                $taxonomies[$entity->name] = (string) $entity->storage->handle;
            }
        }

        $planner = new EdgePlanner($this->naming);

        ksort($byEntity);
        ksort($columns);
        ksort($joinTables);
        ksort($taxonomies);

        return new StorageManifest(
            $byEntity,
            $planner->plan($schema),
            $columns,
            $joinTables,
            $taxonomies,
            $planner->planTaxonomies($schema),
        );
    }
}
