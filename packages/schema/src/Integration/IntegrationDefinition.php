<?php

declare(strict_types=1);

namespace Eleph\Schema\Integration;

use Eleph\Schema\Ir\ConfigParameter;

/**
 * An external system an entity can be exposed to.
 *
 * Distinct from a pattern, and the difference is what they contribute. A pattern is a
 * fragment of an entity spec: it adds fields, edges, actions. An integration adds no
 * structure at all — it says "this entity is visible to GraphQL" and carries the
 * settings that exposure needs.
 *
 * Defined in PHP by the package that provides it rather than as user YAML, because the
 * keys are a property of the integration rather than a choice the project makes. That
 * also keeps the core ignorant: `packages/schema` validates `singular` against this
 * declaration without knowing what GraphQL is.
 */
final readonly class IntegrationDefinition
{
    /**
     * @param array<string, ConfigParameter> $projectConfig Settings declared once, on the project.
     * @param array<string, ConfigParameter> $entityConfig  Settings each exposed entity supplies.
     * @param array<string, ConfigParameter> $queryConfig   Settings a declared query supplies to be exposed.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $projectConfig = [],
        public array $entityConfig = [],
        public array $queryConfig = [],
    ) {
    }
}
