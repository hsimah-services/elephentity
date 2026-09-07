<?php

declare(strict_types=1);

namespace Eleph\WordPress\Sql;

use Eleph\Schema\Ir\Schema;

/**
 * Decides where every edge lives, once.
 *
 * Placement is inferred rather than declared: one-to-many puts the key on the many
 * side, many-to-one and one-to-one keep it local, and many-to-many derives a join
 * table. If the framework can work it out, letting a human choose is a chance for two
 * entities to disagree.
 */
final readonly class EdgePlanner
{
    public function __construct(private Naming $naming = new Naming())
    {
    }

    /**
     * @return array<string, EdgePlacement> Keyed by "Entity.edge".
     */
    public function plan(Schema $schema): array
    {
        $placements = [];

        foreach ($schema->entities as $entity) {
            foreach ($entity->edges as $edge) {
                $target = $schema->entity($edge->to);

                if (null === $target) {
                    continue;
                }

                // Translated once, here. Everything downstream — the placement, the
                // manifest, the adaptor — sees storage's enum, so nothing at run time
                // has to load the spec compiler to know what a relation is.
                $relation = $edge->relation()->forStorage();
                $key = $entity->name . '.' . $edge->name;

                if ($relation->needsJoinTable()) {
                    $placements[$key] = new EdgePlacement(
                        entity: $entity->name,
                        edge: $edge->name,
                        target: $target->name,
                        relation: $relation,
                        table: $this->naming->joinTable($entity->storage->table, $edge->name),
                        localColumn: $this->naming->joinColumn($entity->name),
                        targetColumn: $this->naming->joinColumn($target->name),
                        targetTable: $this->naming->table($target->storage->table),
                    );

                    continue;
                }

                $placements[$key] = $relation->keyIsLocal()
                    ? new EdgePlacement(
                        entity: $entity->name,
                        edge: $edge->name,
                        target: $target->name,
                        relation: $relation,
                        table: $this->naming->table($entity->storage->table),
                        localColumn: $this->naming->column($edge->name) . '_id',
                        targetTable: $this->naming->table($target->storage->table),
                    )
                    : new EdgePlacement(
                        entity: $entity->name,
                        edge: $edge->name,
                        target: $target->name,
                        relation: $relation,
                        table: $this->naming->table($target->storage->table),
                        localColumn: $this->naming->foreignKeyColumn($edge->inverse?->nameFor($entity->name) ?? lcfirst($entity->name)),
                        targetTable: $this->naming->table($target->storage->table),
                    );
            }
        }

        return $placements;
    }
}
