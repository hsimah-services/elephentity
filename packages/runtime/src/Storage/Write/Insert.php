<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use PheFr\Runtime\Identity\Identifier;
use PheFr\Runtime\Identity\PendingId;

final readonly class Insert implements WriteOperation
{
    /**
     * @param array<string, scalar|null> $values Primitive form; processors have already run.
     */
    public function __construct(
        private string $entity,
        private PendingId $id,
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

    public function pendingId(): PendingId
    {
        return $this->id;
    }
}
