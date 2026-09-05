<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use PheFr\Runtime\Identity\Identifier;

/**
 * Attach one entity to another along a named edge.
 *
 * The port says what the link *is*, never how it is stored: whether that becomes a
 * foreign key update or a row in a join table depends on the relation, and only the
 * adaptor knows the physical schema. Keeping that decision below the port is what
 * stops SQL shapes leaking into the unit of work.
 */
final readonly class Link implements WriteOperation
{
    public function __construct(
        private string $entity,
        public string $edge,
        public Identifier $from,
        public Identifier $to,
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
