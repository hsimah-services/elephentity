<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Integration;

use Eleph\Schema\Integration\IntegrationDefinition;
use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Ir\ConfigType;

/**
 * Exposure to WPGraphQL, declared as an integration.
 *
 * Opt-in per entity, deliberately. Extra lines in a spec are a small price for the API
 * surface being visible in it: adding an entity should not silently widen what the
 * outside world can read.
 */
final readonly class WpGraphQL
{
    public const NAME = 'wpgraphql';

    public static function definition(): IntegrationDefinition
    {
        return new IntegrationDefinition(
            name: self::NAME,
            description: 'Exposes entities to WPGraphQL as object types, connections and mutations.',
            projectConfig: [
                'rootQueries' => new ConfigParameter(
                    name: 'rootQueries',
                    type: ConfigType::Bool,
                    description: 'Register a root field per exposed entity. Off means the graph is reachable only through relationships.',
                    default: true,
                    hasDefault: true,
                ),
                'mutations' => new ConfigParameter(
                    name: 'mutations',
                    type: ConfigType::Bool,
                    description: 'Register create, update and action mutations.',
                    default: true,
                    hasDefault: true,
                ),
            ],
            entityConfig: [
                // Required, with no attempt at a default. WPGraphQL asks for both names
                // for the same reason we do: nothing should pluralise on your behalf,
                // and "Inventory Entry" / "Inventory" is exactly the case that breaks
                // anything that tries.
                'singular' => new ConfigParameter(
                    name: 'singular',
                    type: ConfigType::String,
                    description: 'GraphQL type name, e.g. ClogItem.',
                ),
                'plural' => new ConfigParameter(
                    name: 'plural',
                    type: ConfigType::String,
                    description: 'Plural for root fields and connections, e.g. ClogItems.',
                ),
            ],
        );
    }
}
