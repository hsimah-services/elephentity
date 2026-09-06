<?php

declare(strict_types=1);

namespace Eleph\Runtime\Identity;

/**
 * A placeholder for a row this unit of work is about to create.
 *
 * Server-generated ids mean a commit that creates a Post and its Comments must insert
 * the Post, read back its id, then insert the Comments. Until that happens the
 * Comments still need something to point at, and that is this. The unit of work orders
 * writes by dependency and resolves each placeholder to a real EntityId as its target
 * is flushed.
 *
 * Identity here is reference identity: two pending rows are the same row only if they
 * are the same object, because there is nothing else yet to compare.
 */
final class PendingId implements Identifier
{
    private static int $counter = 0;

    private readonly int $sequence;

    public function __construct(public readonly string $entity)
    {
        $this->sequence = ++self::$counter;
    }

    public function isPersisted(): bool
    {
        return false;
    }

    public function equals(Identifier $other): bool
    {
        return $other === $this;
    }

    public function __toString(): string
    {
        return sprintf('pending:%s#%d', $this->entity, $this->sequence);
    }
}
