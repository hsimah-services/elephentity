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
    * @param array<string, PolicyDefinition>  $readPolicies Ordered; declaration order is execution order.
    * @param array<string, PolicyDefinition>  $writePolicies Ordered; declaration order is execution order.
     * @param array<string, mixed>                $config       Pattern configuration, resolved and defaulted.
     * @param array<string, array<string, mixed>> $integrations Keyed by integration name.
     * @param list<string>                        $appliedPatterns Every pattern that actually
     *                                                              applies, including ones pulled
     *                                                              in transitively through another
     *                                                              pattern's own `use:` — unlike
     *                                                              $uses, which is only what this
     *                                                              entity's own spec names.
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
        public array $integrations = [],
        public array $appliedPatterns = [],
        public array $readPolicies = [],
        public array $writePolicies = [],
        public TerminalRule $terminalRead = TerminalRule::Deny,
        public TerminalRule $terminalWrite = TerminalRule::Deny,
    ) {
    }

    /**
     * Whether this entity is exposed through an integration, and how.
     *
     * Opt-in: an entity says nothing and is not exposed, which is the right default for
     * anything that widens a public surface.
     *
     * @return array<string, mixed>|null
     */
    public function exposedVia(string $integration): ?array
    {
        return $this->integrations[$integration] ?? null;
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
