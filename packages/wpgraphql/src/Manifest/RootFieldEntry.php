<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * The two root fields an exposed entity gets: one by id, one collection.
 *
 * Without these the graph has types and no way in — every entity would be reachable
 * only by traversing from something else, and nothing would be the something else.
 *
 * Both names are supplied rather than derived. Deriving the plural would mean
 * pluralising, and the generator does not pluralise anywhere else for the reason
 * "Inventory Entry" / "Inventory" demonstrates.
 */
final readonly class RootFieldEntry
{
    public function __construct(
        public string $type,
        public string $plural,
        public string $entity,
    ) {
    }

    /**
     * `clogItem(id: …)` — GraphQL fields are conventionally camelCase.
     */
    public function single(): string
    {
        return lcfirst($this->type);
    }

    /**
     * `clogItems(first: …, after: …)`.
     */
    public function collection(): string
    {
        return lcfirst($this->plural);
    }
}
