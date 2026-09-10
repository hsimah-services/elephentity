<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\Error\CompilationResult;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\Schema\Wire\IrCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `required: true` on an edge — the to-one case `CompilationFailureTest` covers the
 * refusal for (a to-many edge that declares it).
 */
#[CoversClass(SchemaCompiler::class)]
final class EdgeRequiredTest extends TestCase
{
    public function testARequiredToOneEdgeCompiles(): void
    {
        $compiled = $this->compile();

        self::assertTrue($compiled->isSuccess());

        $edge = $compiled->schema()->entity('PointsTransaction')?->edge('user');

        self::assertNotNull($edge);
        self::assertTrue($edge->required);
    }

    public function testAnEdgeWithNoRequiredKeyDefaultsToFalse(): void
    {
        $edge = $this->compile()->schema()->entity('PointsTransaction')?->edge('reference');

        self::assertNotNull($edge);
        self::assertFalse($edge->required);
    }

    public function testRequiredSurvivesTheWireRoundTrip(): void
    {
        $decoded = IrCodec::decode(IrCodec::encode($this->compile()->schema()));

        $edge = $decoded->entity('PointsTransaction')?->edge('user');

        self::assertNotNull($edge);
        self::assertTrue($edge->required);
    }

    private function compile(): CompilationResult
    {
        return (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/fixtures/edge-required'),
        );
    }
}
