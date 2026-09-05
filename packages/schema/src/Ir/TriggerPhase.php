<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * When a trigger runs relative to the transaction.
 *
 * PreCommit runs inside the transaction *after* the entity's writes are flushed but
 * before COMMIT — not before the writes, so that server-generated IDs already exist.
 * A throw there rolls back the entire commit, and mutation is forbidden so the
 * in-transaction path stays a single pass with no cascades.
 *
 * PostCommit runs once the transaction is closed. A throw is logged and the remaining
 * triggers still run. Mutation is allowed, because a write there is simply a new unit
 * of work rather than an extension of the current one.
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
