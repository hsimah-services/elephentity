<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\WPGraphQL\Manifest\Manifest;
use RuntimeException;

/**
 * The canonical spec's compiled GraphQL manifest, frozen as a fixture rather than
 * compiled here: the builder that produces it (elephentity-codegen-wpgraphql) is a
 * separate repository now (elephentity#62), and these tests are about the runtime
 * reading a manifest, not about compiling one.
 *
 * Shared because more than one test needs the whole surface and loading it is the
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

        /** @var mixed $manifest */
        $manifest = require __DIR__ . '/fixtures/graphql-manifest.php';

        if (!$manifest instanceof Manifest) {
            throw new RuntimeException('fixtures/graphql-manifest.php did not return a Manifest.');
        }

        return self::$manifest = $manifest;
    }
}
