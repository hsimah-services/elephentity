<?php

declare(strict_types=1);

namespace Eleph\Cli;

use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\WPGraphQL\Integration\WpGraphQL;

/**
 * Which integrations this installation offers.
 *
 * The CLI is the composition root — the only layer that knows which packages are
 * present — so it assembles the registry and hands it down. `packages/schema`
 * validates against whatever arrives without ever naming one.
 */
final readonly class Integrations
{
    public static function registry(): IntegrationRegistry
    {
        return new IntegrationRegistry(
            WpGraphQL::definition(),
        );
    }
}
