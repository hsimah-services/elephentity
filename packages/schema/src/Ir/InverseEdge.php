<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * One edge, seen from the entity it points at.
 *
 * Not a second edge — the same one, read backwards. `Inventory.item` is the only
 * relationship in the schema, and `Item.inventoryEntries` is a view of it, which is
 * why this carries the declaring entity and edge rather than any storage of its own.
 *
 * Derived in one place because three consumers need the same answer: the entity
 * generator emits the accessor, the GraphQL manifest exposes it, and the validator
 * refuses a name the target already uses. Three derivations would be three chances to
 * disagree about what a spec says.
 */
final readonly class InverseEdge
{
    public function __construct(
        /** The entity declaring the forward edge, and the type this accessor returns. */
        public string $declaredBy,
        /** The forward edge's name, which is how storage addresses it in either direction. */
        public string $edge,
        /** The accessor's name on the target entity. */
        public string $name,
        /** Whether the target points back at exactly one row. */
        public bool $unique,
        public ?string $description = null,
    ) {
    }
}
