<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Identity\Identifier;

/**
 * One entity's pending changes: the buffer that records them and the context that
 * reads them back.
 *
 * Both interfaces on one object because they are two views of the same state. Setters
 * write into it, verifiers read from it, and neither touches storage until commit.
 */
final class Mutation implements MutationBuffer, MutationContext
{
    /** @var array<string, mixed> */
    private array $changes = [];

    /** @var array<string, PendingEdge> */
    private array $edges = [];

    /**
     * @param array<string, mixed> $original The entity as it was, in domain form. Empty on create.
     */
    public function __construct(
        private readonly string $entity,
        private readonly Identifier $target,
        private readonly array $original = [],
    ) {
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function target(): Identifier
    {
        return $this->target;
    }

    public function isCreate(): bool
    {
        return !$this->target->isPersisted();
    }

    public function set(string $field, mixed $value): void
    {
        $this->changes[$field] = $value;
    }

    public function isChanged(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }

    public function original(string $field): mixed
    {
        return $this->original[$field] ?? null;
    }

    /**
     * Falls back to the original for untouched fields, so a verifier always sees what
     * the row will actually hold rather than only what this mutation names.
     */
    public function pending(string $field): mixed
    {
        return array_key_exists($field, $this->changes)
            ? $this->changes[$field]
            : $this->original($field);
    }

    public function changes(): array
    {
        return $this->changes;
    }

    public function edge(string $edge): EdgeMutation
    {
        return $this->edges[$edge] ??= new PendingEdge($edge);
    }

    /**
     * @return list<Identifier>
     */
    public function pendingEdge(string $edge): array
    {
        return ($this->edges[$edge] ?? null)?->added() ?? [];
    }

    public function isEdgeChanged(string $edge): bool
    {
        return isset($this->edges[$edge]) && !$this->edges[$edge]->isEmpty();
    }

    /**
     * @return array<string, PendingEdge>
     */
    public function edgeChanges(): array
    {
        return array_filter($this->edges, static fn (PendingEdge $e): bool => !$e->isEmpty());
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes && [] === $this->edgeChanges();
    }

    /**
     * Rows this mutation links to that have yet to be written.
     *
     * @return list<Identifier>
     */
    public function dependencies(): array
    {
        $unresolved = [];

        foreach ($this->edgeChanges() as $edge) {
            foreach ($edge->unresolved() as $identifier) {
                $unresolved[] = $identifier;
            }
        }

        return $unresolved;
    }
}
