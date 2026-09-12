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
     * @param list<string>                   $interfaces
     */
    public function __construct(
        public string $name,
        public string $entity,
        public array $fields,
        public array $connections = [],
        public ?string $description = null,
        /**
         * Every entity is a Node: it has an id, and the whole point of the interface
         * is that a client can refetch anything it has cached without knowing what it
         * cached. Carried here rather than assumed in the registrar because the
         * manifest is where the API surface is decided.
         */
        public array $interfaces = ['Node'],
    ) {
    }
}
