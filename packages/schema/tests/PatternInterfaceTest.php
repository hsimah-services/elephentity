<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\Error\CompilationResult;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\Schema\Wire\IrCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `interface: true` on a pattern is the one thing about it that reaches the wire as
 * its own IR node — everything else about a pattern is gone by the time an Schema
 * exists, folded into the fields and edges it contributed.
 */
#[CoversClass(SchemaCompiler::class)]
#[CoversClass(Schema::class)]
final class PatternInterfaceTest extends TestCase
{
    public function testAPatternThatOptsInIsCarriedByTheSchema(): void
    {
        $pattern = $this->schema()->pattern('Auditable');

        self::assertNotNull($pattern);
        self::assertSame(['createdAt', 'updatedAt'], array_keys($pattern->fields));
        self::assertSame([], $pattern->edges);
    }

    public function testAPatternThatDoesNotOptInIsAbsent(): void
    {
        // Silent contributes a field to Post like any other pattern; it just does not
        // ask for a shared interface, so it carries no weight of its own in the schema.
        self::assertNull($this->schema()->pattern('Silent'));
    }

    public function testAPatternNobodyUsesIsAbsentEvenThoughItOptedIn(): void
    {
        // A class generated for a pattern nothing applies would be dead code no spec
        // asked for.
        self::assertNull($this->schema()->pattern('Unused'));
    }

    public function testEveryEntityUsingThePatternStillGetsItsOwnFields(): void
    {
        // The shared declaration is additional information, not a replacement for the
        // merge every entity already goes through.
        $schema = $this->schema();

        self::assertNotNull($schema->entity('Post')?->field('createdAt'));
        self::assertNotNull($schema->entity('Comment')?->field('createdAt'));
    }

    public function testThePatternSurvivesTheWireRoundTrip(): void
    {
        $decoded = IrCodec::decode(IrCodec::encode($this->schema()));

        $pattern = $decoded->pattern('Auditable');

        self::assertNotNull($pattern);
        self::assertSame(['createdAt', 'updatedAt'], array_keys($pattern->fields));
        self::assertSame(['Auditable', 'Silent'], $decoded->entity('Post')?->appliedPatterns);
    }

    private function schema(): Schema
    {
        $compiled = $this->compile('pattern-interface');

        self::assertTrue($compiled->isSuccess());

        return $compiled->schema();
    }

    private function compile(string $fixture): CompilationResult
    {
        return (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/fixtures/' . $fixture),
        );
    }
}
