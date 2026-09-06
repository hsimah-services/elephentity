<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * A fully resolved entity: its own spec with every pattern merged in.
 */
final readonly class EntityDefinition
{
    /**
     * @param list<string>                     $uses
     * @param array<string, FieldDefinition>   $fields
     * @param array<string, EdgeDefinition>    $edges
     * @param array<string, QueryDefinition>   $queries
     * @param array<string, ActionDefinition>  $actions
     * @param array<string, TriggerDefinition> $triggers Ordered; declaration order is execution order.
     * @param array<string, mixed>             $config   Pattern configuration, resolved and defaulted.
     */
    public function __construct(
        public string $name,
        public StorageDefinition $storage,
        public string $sourceFile,
        public ?string $description = null,
        public array $uses = [],
        public array $fields = [],
        public array $edges = [],
        public array $queries = [],
        public array $actions = [],
        public array $triggers = [],
        public array $config = [],
    ) {
    }

    public function field(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    public function edge(string $name): ?EdgeDefinition
    {
        return $this->edges[$name] ?? null;
    }

    /**
     * A configuration value contributed by one of this entity's patterns.
     *
     * Consumers read the keys they know: the WordPress adaptor asks for `visibility`
     * without the core ever learning what one is. Keys collide across patterns at
     * compile time, so a value here has exactly one source.
     */
    public function configured(string $key, mixed $fallback = null): mixed
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $fallback;
    }
}
