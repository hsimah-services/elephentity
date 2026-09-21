<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Identity\EntityId;

/** A completed mutation. A null entity means the result is no longer visible. */
final readonly class MutationResult
{
    public function __construct(public EntityId $id, public ?object $entity)
    {
    }
}
