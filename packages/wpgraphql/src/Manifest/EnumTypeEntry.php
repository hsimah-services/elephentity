<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * A GraphQL enum type.
 *
 * GraphQL enum values are conventionally SCREAMING_SNAKE_CASE while the spec writes
 * them lowercase, so the manifest carries both: the name a client sends and the value
 * the database holds.
 */
final readonly class EnumTypeEntry
{
    /**
     * @param array<string, string> $values GraphQL name => stored value.
     */
    public function __construct(
        public string $name,
        public array $values,
    ) {
    }
}
