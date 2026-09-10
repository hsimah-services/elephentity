<?php

declare(strict_types=1);

namespace Eleph\WordPress\Sql;

use Eleph\Runtime\Storage\RelationKind;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\WordPress\Taxonomy\TaxonomyPlacement;

/**
 * Decides where every edge lives, once.
 *
 * Placement is inferred rather than declared: one-to-many puts the key on the many
 * side, many-to-one and one-to-one keep it local, and many-to-many derives a join
 * table. If the framework can work it out, letting a human choose is a chance for two
 * entities to disagree.
 *
 * A many-to-many edge pointing at a taxonomy-backed entity is the one relation this
 * skips entirely — see `planTaxonomies()`. There is no column or join table for it to
 * place; `wp_term_relationships` already is one.
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

                if (RelationKind::ManyToMany === $relation && self::isTaxonomy($target)) {
                    continue;
                }

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

    /**
     * The many-to-many edges `plan()` skipped, because their target is a term rather
     * than a row in a table it could place a column or join table against.
     *
     * @return array<string, TaxonomyPlacement> Keyed by "Entity.edge".
     */
    public function planTaxonomies(Schema $schema): array
    {
        $placements = [];

        foreach ($schema->entities as $entity) {
            foreach ($entity->edges as $edge) {
                $target = $schema->entity($edge->to);

                if (null === $target || !self::isTaxonomy($target)) {
                    continue;
                }

                if (RelationKind::ManyToMany !== $edge->relation()->forStorage()) {
                    // Not the shape a term relationship can express. Falling through to
                    // plan() means it gets an ordinary placement against a table the
                    // target does not have — a spec error this planner has no way to
                    // report, so it is left to surface where the table is missing.
                    continue;
                }

                $placements[$entity->name . '.' . $edge->name] = new TaxonomyPlacement(
                    entity: $entity->name,
                    edge: $edge->name,
                    target: $target->name,
                    taxonomy: (string) $target->storage->handle,
                );
            }
        }

        return $placements;
    }

    /**
     * Whether a pattern has marked this entity's rows as WordPress taxonomy terms
     * rather than rows in a table of its own.
     *
     * Read from pattern configuration, the same extension point `visibility` and
     * `adminMenu` already use for post types — the core schema never learns what a
     * taxonomy is, and this package stays the only thing that does.
     */
    public static function isTaxonomy(EntityDefinition $entity): bool
    {
        return true === $entity->configured('taxonomy', false);
    }
}
