<?php

declare(strict_types=1);

namespace PheFr\WPGraphQL\Manifest;

/**
 * One field on a GraphQL object type.
 *
 * `accessor` is the whole convention: a spec field named `title` becomes a GraphQL
 * field `title` resolved by calling `getTitle()` on the read model. Because the
 * generator produced both ends from the same spec, the two cannot drift — and the
 * conformance check can prove it by comparing this manifest against the entity class.
 */
final readonly class FieldEntry
{
    public function __construct(
        public string $name,
        public GraphQLType $type,
        public string $accessor,
        public ?string $description = null,
    ) {
    }
}
