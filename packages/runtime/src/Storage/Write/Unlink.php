<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use PheFr\Runtime\Identity\Identifier;

final readonly class Unlink implements WriteOperation
{
    public function __construct(
        private string $entity,
        public string $edge,
        public Identifier $from,
        /** Null clears the edge entirely, which is how a wholesale replacement starts. */
        public ?Identifier $to = null,
    ) {
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function target(): Identifier
    {
        return $this->from;
    }
}
