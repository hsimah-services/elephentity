<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests\Support;

use Eleph\Schema\Integration\IntegrationDefinition;
use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Ir\ConfigType;

/**
 * The registry the shared `valid` fixture needs to compile.
 *
 * The fixture exercises an integration, and integrations are defined by the packages
 * that provide them — but `packages/schema` cannot depend on `packages/wpgraphql`
 * without inverting the layering. So this restates the shape the fixture relies on.
 *
 * The duplication is deliberate and guarded: a test in the wpgraphql package asserts
 * that the real definition still matches this one, so adding a required key there
 * fails there rather than mysteriously here.
 */
final readonly class TestIntegrations
{
    public static function registry(): IntegrationRegistry
    {
        return new IntegrationRegistry(
            new IntegrationDefinition(
                name: 'wpgraphql',
                description: 'Stand-in for the real definition; see the note above.',
                entityConfig: [
                    'singular' => new ConfigParameter('singular', ConfigType::String),
                    'plural' => new ConfigParameter('plural', ConfigType::String),
                ],
                queryConfig: [
                    'field' => new ConfigParameter('field', ConfigType::String),
                ],
            ),
        );
    }
}
