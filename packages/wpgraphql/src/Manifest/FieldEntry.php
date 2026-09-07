<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * One field on a GraphQL object type.
 *
 * `accessor` is the whole convention: a spec field named `title` becomes a GraphQL
 * field `title` resolved by calling `getTitle()` on the read model. Because the
 * generator produced both ends from the same spec, the two cannot drift — and the
 * conformance check can prove it by comparing this manifest against the entity class.
 *
 * `encoding` completes it. The accessor returns a domain value and the client was
 * promised a wire type; whoever decided the wire type is the only thing that can say
 * how to get there, so it is recorded here rather than guessed at in the resolver.
 */
final readonly class FieldEntry
{
    public function __construct(
        public string $name,
        public GraphQLType $type,
        public string $accessor,
        public ?string $description = null,
        public FieldEncoding $encoding = FieldEncoding::Value,
        /** The declared type whose processor unwinds the value. Processor encoding only. */
        public ?string $valueType = null,
    ) {
    }
}
