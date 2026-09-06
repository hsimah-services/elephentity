<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * One entity as a GraphQL object type.
 */
final readonly class ObjectTypeEntry
{
    /**
     * @param array<string, FieldEntry>      $fields
     * @param array<string, ConnectionEntry> $connections
     */
    public function __construct(
        public string $name,
        public string $entity,
        public array $fields,
        public array $connections = [],
        public ?string $description = null,
    ) {
    }
}
