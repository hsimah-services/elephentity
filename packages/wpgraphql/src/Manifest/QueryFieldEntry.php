<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * A declared query published at the root.
 *
 * Opt-in per query. An entity being in the graph does not mean every finder it
 * declares belongs in the public API — a query written to back an admin screen should
 * not become world-readable because the entity it reads is.
 */
final readonly class QueryFieldEntry
{
    /**
     * @param array<string, GraphQLType> $args
     */
    public function __construct(
        public string $field,
        public string $type,
        public bool $isCollection,
        public string $entity,
        public string $query,
        public array $args = [],
        public ?string $description = null,
    ) {
    }
}
