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

        foreach ($schema->entities as $entity) {
            $table = $this->naming->table($entity);

            if (isset($byTable[$table])) {
                $byEntity[$entity->name] = $byTable[$table];
            }

            foreach ($entity->fields as $field) {
                $columns[$entity->name][$field->name] = $this->naming->column($field->name);
            }
        }

        ksort($byEntity);
        ksort($columns);

        return new StorageManifest(
            $byEntity,
            (new EdgePlanner($this->naming))->plan($schema),
            $columns,
        );
    }
}
