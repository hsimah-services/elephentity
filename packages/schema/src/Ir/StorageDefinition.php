<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

final readonly class StorageDefinition
{
    public function __construct(
        public string $driver,
        public string $table,
        /**
         * The name the storage system knows this entity by: a post type slug under the
         * wordpress driver, a collection name elsewhere. Deliberately driver-agnostic,
         * with driver-specific validation applied by the compiler.
         */
        public ?string $handle = null,
    ) {
    }
}
