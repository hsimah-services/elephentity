<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Identity\Identifier;

final readonly class Update implements WriteOperation
{
    /**
     * @param array<string, scalar|null> $values Only the fields that changed.
     */
    public function __construct(
        private string $entity,
        private EntityId $id,
        public array $values,
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
