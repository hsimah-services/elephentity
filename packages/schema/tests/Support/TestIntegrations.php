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
 * that provide them — but `packages/schema` cannot depend on a platform package
 * without inverting the layering, and since elephentity#79 the real `wpgraphql`
 * integration is defined in a separate repository
 * (`elephentity-wpgraphql`) entirely. So this restates the shape the fixture relies on.
 *
 * The duplication is deliberate but no longer guarded by a same-repository test: there
 * is nothing here that fails automatically if `elephentity-wpgraphql`'s definition
 * grows a new required key. A mismatch surfaces as a real project's `wpgraphql`
 * integration failing to compile, the same way any other cross-repository drift does —
 * see `.llms/cross-repo.md`.
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
