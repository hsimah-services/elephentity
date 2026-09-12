<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WPGraphQL\Integration\WpGraphQL;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use RuntimeException;

/**
 * The canonical spec, compiled once.
 *
 * Shared because more than one test needs the whole surface and compiling it is the
 * slowest thing in the package.
 */
final class Fixture
{
    private static ?Manifest $manifest = null;

    public static function manifest(): Manifest
    {
        if (null !== self::$manifest) {
            return self::$manifest;
        }

        $compiled = (new SchemaCompiler(integrations: new IntegrationRegistry(WpGraphQL::definition())))->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        if (!$compiled->isSuccess()) {
            throw new RuntimeException('The canonical spec no longer compiles.');
        }

        return self::$manifest = (new ManifestBuilder())->build($compiled->schema());
    }
}
