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
}
