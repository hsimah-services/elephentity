<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Identity\Identifier;

/**
 * Pending changes to one edge, recorded until the commit resolves them.
 *
 * Holds identifiers rather than entities, so a link can be made to a row that does not
 * exist yet: attaching a Comment to a Post being created in the same commit records a
 * PendingId, which the unit of work substitutes once the Post is inserted.
 */
final class PendingEdge implements EdgeMutation
{
    /** @var list<Identifier> */
    private array $added = [];

    /** @var list<Identifier> */
    private array $removed = [];

    /** @var list<Identifier>|null */
    private ?array $replacement = null;

    public function __construct(public readonly string $name)
    {
    }

    public function add(Identifier $target): void
    {
        $this->added[] = $target;
    }

    public function remove(Identifier $target): void
    {
        $this->removed[] = $target;
    }

    public function set(array $targets): void
    {
        // A wholesale replacement supersedes anything recorded before it.
        $this->replacement = array_values($targets);
        $this->added = [];
        $this->removed = [];
    }

    public function isEmpty(): bool
    {
        return [] === $this->added && [] === $this->removed && null === $this->replacement;
    }

    /**
     * @return list<Identifier>
     */
    public function added(): array
    {
        return $this->replacement ?? $this->added;
    }

    /**
     * @return list<Identifier>
     */
    public function removed(): array
    {
        return $this->removed;
    }

    public function isReplacement(): bool
    {
        return null !== $this->replacement;
    }

    /**
     * Every row this edge points at that has yet to be written.
     *
     * @return list<Identifier>
     */
    public function unresolved(): array
    {
        return array_values(array_filter(
            [...$this->added(), ...$this->removed],
            static fn (Identifier $id): bool => !$id->isPersisted(),
        ));
    }
}
