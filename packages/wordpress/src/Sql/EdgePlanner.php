<?php

declare(strict_types=1);

namespace PheFr\WordPress\Sql;

use PheFr\Schema\Ir\Schema;

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

                $relation = $edge->relation();
                $key = $entity->name . '.' . $edge->name;

                if ($relation->needsJoinTable()) {
                    $placements[$key] = new EdgePlacement(
                        entity: $entity->name,
                        edge: $edge->name,
                        target: $target->name,
                        relation: $relation,
                        table: $this->naming->joinTable($entity, $edge),
                        localColumn: $this->naming->joinColumn($entity->name),
                        targetColumn: $this->naming->joinColumn($target->name),
                        targetTable: $this->naming->table($target),
                    );

                    continue;
                }

                $placements[$key] = $relation->keyIsLocal()
                    ? new EdgePlacement(
                        entity: $entity->name,
                        edge: $edge->name,
                        target: $target->name,
                        relation: $relation,
                        table: $this->naming->table($entity),
                        localColumn: $this->naming->column($edge->name) . '_id',
                        targetTable: $this->naming->table($target),
                    )
                    : new EdgePlacement(
                        entity: $entity->name,
                        edge: $edge->name,
                        target: $target->name,
                        relation: $relation,
                        table: $this->naming->table($target),
                        localColumn: $this->naming->foreignKeyColumn($entity, $edge),
                        targetTable: $this->naming->table($target),
                    );
            }
        }

        return $placements;
    }
}
