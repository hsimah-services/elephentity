<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Identity\EntityId;

/**
 * A row this commit will remove.
 *
 * Deliberately not a Mutation. A mutation carries pending field values and is verified;
 * a deletion carries neither, and pretending otherwise would mean every verifier had to
 * ask whether the row it was checking was about to cease existing.
 */
final readonly class Deletion
{
    public function __construct(
        public string $entity,
        public EntityId $id,
    ) {
    }

    public function key(): string
    {
        return sprintf('%s#%s', $this->entity, $this->id);
    }
}
