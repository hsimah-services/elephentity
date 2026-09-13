<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\Managed;
use Eleph\Schema\Ir\Schema;
use Eleph\WordPress\Account\AccountFields;
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
        $accounts = [];

        foreach ($schema->entities as $entity) {
            if (EdgePlanner::isTaxonomy($entity)) {
                $taxonomies[$entity->name] = (string) $entity->storage->handle;
            }

            if (EdgePlanner::isAccount($entity)) {
                $accounts[$entity->name] = $this->accountFields($entity);
            }
        }

        $planner = new EdgePlanner($this->naming);

        ksort($byEntity);
        ksort($columns);
        ksort($joinTables);
        ksort($taxonomies);
        ksort($accounts);

        return new StorageManifest(
            $byEntity,
            $planner->plan($schema),
            $columns,
            $joinTables,
            $taxonomies,
            $planner->planTaxonomies($schema),
            $accounts,
        );
    }

    private function accountFields(EntityDefinition $entity): AccountFields
    {
        $created = null;
        $modified = null;

        foreach ($entity->fields as $field) {
            if (Managed::Created === $field->managed) {
                $created = $field->name;
            }

            if (Managed::Modified === $field->managed) {
                $modified = $field->name;
            }
        }

        return new AccountFields($entity->name, $created, $modified);
    }
}
