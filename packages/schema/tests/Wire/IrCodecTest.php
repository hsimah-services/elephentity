<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests\Wire;

use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\Schema\Wire\IrCodec;
use Eleph\Schema\Wire\WireException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The wire format is what makes a generator in another language possible, so the test
 * that matters is that nothing is lost crossing it.
 */
#[CoversClass(IrCodec::class)]
final class IrCodecTest extends TestCase
{
    public function testTheCanonicalSpecSurvivesARoundTrip(): void
    {
        $schema = $this->schema();

        // Equality rather than identity: the decoded graph is a different set of
        // objects that has to be indistinguishable from the original, because that is
        // exactly what a builder on the far side of a pipe receives.
        self::assertEquals($schema, IrCodec::decode(IrCodec::encode($schema)));
    }

    public function testTheEncodedFormIsActuallyJson(): void
    {
        // Encoding to something PHP can hold but json_encode chokes on would work in
        // every in-process test and fail the first time a subprocess is involved.
        $json = json_encode(IrCodec::encode($this->schema()), JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertEquals($this->schema(), IrCodec::decode($decoded));
    }

    public function testBackedEnumsTravelAsTheirValue(): void
    {
        foreach ($this->postEdges($this->wire()) as $edge) {
            // Readable on the wire, and readable by a builder that has never heard of
            // PHP: "many", not an ordinal or a class name.
            self::assertContains($edge['cardinality'], ['one', 'many']);
            self::assertIsString($edge['onDelete']);
        }
    }

    public function testAnUnknownEnumCaseIsRefused(): void
    {
        $encoded = $this->wire();

        /** @var array<string, mixed> $entities */
        $entities = $encoded['entities'];
        /** @var array<string, mixed> $post */
        $post = $entities['Post'];
        /** @var array<string, mixed> $edges */
        $edges = $post['edges'];
        /** @var array<string, mixed> $edge */
        $edge = $edges['comments'];

        $edge['cardinality'] = 'several';
        $edges['comments'] = $edge;
        $post['edges'] = $edges;
        $entities['Post'] = $post;
        $encoded['entities'] = $entities;

        // A builder sending a cardinality nobody has heard of must not quietly become
        // "one": the whole point of the gate is that wrong output never gets signed.
        $this->expectException(WireException::class);
        $this->expectExceptionMessageMatches('/no case matching/');

        IrCodec::decode($encoded);
    }

    public function testAMissingRequiredFieldIsRefused(): void
    {
        $encoded = $this->wire();

        /** @var array<string, mixed> $project */
        $project = $encoded['project'];
        unset($project['driver']);
        $encoded['project'] = $project;

        $this->expectException(WireException::class);
        $this->expectExceptionMessageMatches('/missing required field "driver"/');

        IrCodec::decode($encoded);
    }

    /**
     * The schema exactly as a builder receives it: encoded, serialised, parsed back.
     *
     * Tests index this rather than the raw encode() output because encode() emits
     * stdClass for map-shaped fields, and a builder never sees those — it sees JSON.
     *
     * @return array<string, mixed>
     */
    private function wire(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            json_encode(IrCodec::encode($this->schema()), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /**
     * @param array<string, mixed> $encoded
     *
     * @return list<array<string, mixed>>
     */
    private function postEdges(array $encoded): array
    {
        /** @var array<string, mixed> $entities */
        $entities = $encoded['entities'];
        /** @var array<string, mixed> $post */
        $post = $entities['Post'];
        /** @var array<string, array<string, mixed>> $edges */
        $edges = $post['edges'];

        self::assertNotSame([], $edges, 'The canonical spec should declare edges.');

        return array_values($edges);
    }

    private function schema(): Schema
    {
        $compiled = (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/../fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess(), 'The canonical fixture should compile.');

        return $compiled->schema();
    }
}
