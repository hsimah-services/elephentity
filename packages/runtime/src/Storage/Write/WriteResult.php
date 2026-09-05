<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use OutOfBoundsException;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Identity\PendingId;
use SplObjectStorage;

/**
 * What a batch produced: chiefly, the real ids assigned to inserted rows.
 *
 * Keyed by object identity because a pending id has no value to key on — that is the
 * whole reason it exists.
 */
final class WriteResult
{
    /** @var SplObjectStorage<PendingId, EntityId> */
    private SplObjectStorage $assigned;

    public function __construct()
    {
        /** @var SplObjectStorage<PendingId, EntityId> $storage */
        $storage = new SplObjectStorage();
        $this->assigned = $storage;
    }

    public function assign(PendingId $pending, EntityId $id): void
    {
        $this->assigned[$pending] = $id;
    }

    public function wasAssigned(PendingId $pending): bool
    {
        return $this->assigned->contains($pending);
    }

    /**
     * @throws OutOfBoundsException when the batch never inserted that row.
     */
    public function idFor(PendingId $pending): EntityId
    {
        if (!$this->assigned->contains($pending)) {
            throw new OutOfBoundsException(sprintf('No id was assigned to %s.', $pending));
        }

        return $this->assigned[$pending];
    }
}
