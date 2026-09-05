<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * A collection-level finder. Edge traversal is covered by edges, not queries.
 *
 * Generated onto an injectable finder rather than as a static on the entity: statics
 * are awkward to inject into and to fake in tests, and it keeps the entity exactly one
 * thing — a single-row read model — rather than also a collection gateway.
 */
final readonly class QueryDefinition implements Contributed
{
    /**
     * @param array<string, ArgumentDefinition> $arguments
     */
    public function __construct(
        public string $name,
        public ReturnDefinition $returns,
        public Origin $origin,
        public array $arguments = [],
        public ?string $description = null,
    ) {
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
