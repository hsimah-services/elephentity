<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * A to-many edge exposed as a GraphQL connection.
 *
 * Connections map straight onto the lazy query the accessor returns: page() answers
 * first/after, count() answers totalCount, and neither hydrates anything the caller
 * did not ask for. That is the reason to-many accessors return a query rather than an
 * array — a resolver slicing an already-hydrated array would have paid for the whole
 * edge before deciding it wanted ten of them.
 */
final readonly class ConnectionEntry
{
    public function __construct(
        public string $name,
        public string $fromType,
        public string $toType,
        public string $accessor,
        /** The spec's name for the edge, which preload() batches on. */
        public string $edge,
        public ?string $description = null,
    ) {
    }
}
