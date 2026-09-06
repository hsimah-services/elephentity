<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * Everything the GraphQL layer registers, computed once at build time.
 *
 * Not generated resolver code, which would be one more tree to keep in step; and not
 * a reflective walk of the IR on every request, which costs time on each boot and
 * gives static analysis nothing to check. A compiled manifest is data: cheap to load,
 * trivial to diff, and checkable against the entity classes by the conformance gate.
 */
final readonly class Manifest
{
    /**
     * @param array<string, ObjectTypeEntry> $objects
     * @param array<string, EnumTypeEntry>   $enums
     * @param array<string, MutationEntry>   $mutations
     */
    public function __construct(
        public array $objects = [],
        public array $enums = [],
        public array $mutations = [],
    ) {
    }
}
