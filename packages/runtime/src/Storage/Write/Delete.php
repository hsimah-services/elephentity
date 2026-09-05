<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Identity\Identifier;

final readonly class Delete implements WriteOperation
{
    public function __construct(
        private string $entity,
        private EntityId $id,
    ) {
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function target(): Identifier
    {
        return $this->id;
    }
}
