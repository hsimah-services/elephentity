<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Identity\EntityId;
use RuntimeException;

/**
 * A delete refused because another row still depends on the target through an edge
 * that says restrict.
 *
 * This is the system doing what the spec says, not a fault, so an API layer should
 * report it to the client rather than mask it as an internal error. The message names
 * the dependents and nothing else, so it is safe to show. It stays a RuntimeException,
 * which is what the planner always threw, so a caller that catches one keeps working.
 */
final class DeletionRestricted extends RuntimeException
{
    public function __construct(
        public readonly string $entity,
        public readonly EntityId $id,
        public readonly string $dependent,
        public readonly string $edge,
        public readonly int $remaining,
    ) {
        parent::__construct(sprintf(
            'Cannot delete %s#%s: %d %s still depend%s on it through "%s". The edge says restrict.',
            $entity,
            (string) $id,
            $remaining,
            $dependent,
            1 === $remaining ? 's' : '',
            $edge,
        ));
    }
}
