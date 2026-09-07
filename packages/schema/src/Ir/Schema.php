<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * The compiled world: every entity with its patterns resolved, and every declared type.
 *
 * This is the single artifact every downstream consumer reads. Without it the entity
 * generator and the GraphQL generator would each grow their own half-answer to "what
 * does a spec field mean" and drift from each other rather than from the spec.
 */
final readonly class Schema
{
    /**
     * @param array<string, EntityDefinition> $entities
     * @param array<string, TypeDefinition>   $types
     */
    public function __construct(
        public ProjectDefinition $project,
        public array $entities = [],
        public array $types = [],
    ) {
    }

    public function entity(string $name): ?EntityDefinition
    {
        return $this->entities[$name] ?? null;
    }

    public function type(string $name): ?TypeDefinition
    {
        return $this->types[$name] ?? null;
    }

    public function hasEntity(string $name): bool
    {
        return isset($this->entities[$name]);
    }

    /**
     * The reverse accessors an entity gets from edges declared elsewhere.
     *
     * Answering it means reading every *other* entity's edges, which is a build-time
     * walk and exactly the sort of thing the IR exists to do once. Declaration order is
     * preserved, so generated output stays stable as entities are added.
     *
     * @return list<InverseEdge>
     */
    public function inversesOf(string $entity): array
    {
        $inverses = [];

        foreach ($this->entities as $declaring) {
            foreach ($declaring->edges as $edge) {
                if ($edge->to !== $entity || null === $edge->inverse) {
                    continue;
                }

                $inverses[] = new InverseEdge(
                    $declaring->name,
                    $edge->name,
                    $edge->inverse->nameFor($declaring->name),
                    $edge->inverse->unique,
                    $edge->description,
                );
            }
        }

        return $inverses;
    }
}
