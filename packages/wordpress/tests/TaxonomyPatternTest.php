<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\Spec\RawSpec;
use Eleph\Schema\Spec\SpecKind;
use Eleph\Schema\SpecSource;
use Eleph\WordPress\Sql\EdgePlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Taxonomy pattern this package ships (#52 G4, #32): the whole point is that a spec
 * can `use: Taxonomy` with nothing under `spec/patterns/`, because the pattern arrives
 * pooled from `describe` the same way `examples/clog`'s `Site` entity now gets it.
 *
 * This compiles the actual shipped resource — not a hand-copied restatement of it — so
 * a change to `resources/patterns/Taxonomy.yml` that breaks the config it sets is
 * caught here rather than only at `examples/clog` regeneration time.
 */
#[CoversClass(EdgePlanner::class)]
final class TaxonomyPatternTest extends TestCase
{
    public function testAnEntityUsingTheShippedPatternIsATaxonomyEndToEnd(): void
    {
        $result = (new SchemaCompiler(pooledPatterns: [$this->shippedPattern()]))->compile(
            new SpecSource(__DIR__ . '/fixtures/taxonomy-pattern'),
        );

        self::assertTrue($result->isSuccess(), implode("\n", array_map(
            static fn ($error) => $error->describe(),
            $result->errors,
        )));

        $term = $result->schema()->entity('Term');

        self::assertNotNull($term);
        self::assertSame(['Taxonomy'], $term->appliedPatterns);
        self::assertTrue($term->configured('taxonomy', false));
        self::assertTrue(EdgePlanner::isTaxonomy($term));
    }

    private function shippedPattern(): RawSpec
    {
        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile(__DIR__ . '/../resources/patterns/Taxonomy.yml');

        return new RawSpec(SpecKind::Pattern, 'elephentity/wordpress:patterns/Taxonomy.yml', $data);
    }
}
