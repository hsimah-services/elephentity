<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

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
}
