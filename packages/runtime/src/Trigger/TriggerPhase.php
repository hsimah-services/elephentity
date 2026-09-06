<?php

declare(strict_types=1);

namespace Eleph\Runtime\Trigger;

/**
 * When a trigger runs relative to the transaction.
 *
 * PreCommit is inside the transaction and after the entity's writes are flushed, so
 * server-generated ids already exist. A throw rolls the whole commit back, and
 * mutation is forbidden, which keeps the in-transaction path a single pass with no
 * cascades and nothing to detect cycles in.
 *
 * PostCommit is after COMMIT. Mutation is allowed there because a write is a new unit
 * of work rather than an extension of this one — with the consequence that it is not
 * atomic with the commit that caused it, and can itself fire triggers.
 */
enum TriggerPhase: string
{
    case PreCommit = 'preCommit';
    case PostCommit = 'postCommit';

    public function allowsMutation(): bool
    {
        return self::PostCommit === $this;
    }
}
