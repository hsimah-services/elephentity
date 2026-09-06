<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

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
     * @param array<string, ArgumentDefinition>   $arguments
     * @param array<string, array<string, mixed>> $integrations Keyed by integration name.
     */
    public function __construct(
        public string $name,
        public ReturnDefinition $returns,
        public Origin $origin,
        public array $arguments = [],
        public ?string $description = null,
        public array $integrations = [],
    ) {
    }

    /**
     * Whether this query is published through an integration, and how.
     *
     * Separate from the entity's own exposure. An entity being in the graph does not
     * mean every finder it declares belongs at the root — a query written for internal
     * use should not become public by association.
     *
     * @return array<string, mixed>|null
     */
    public function exposedVia(string $integration): ?array
    {
        return $this->integrations[$integration] ?? null;
    }

    /**
     * @param array<string, array<string, mixed>> $integrations
     */
    public function withIntegrations(array $integrations): self
    {
        return new self(
            $this->name,
            $this->returns,
            $this->origin,
            $this->arguments,
            $this->description,
            $integrations,
        );
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
