<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage\Write;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;

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
