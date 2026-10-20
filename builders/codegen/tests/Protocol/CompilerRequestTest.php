<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests\Protocol;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Protocol\CompilerRequest;
use Eleph\Codegen\Protocol\Envelope;
use Eleph\Codegen\Protocol\ProtocolException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The upstream half of the pipe: what Elephentity sends and what this program will
 * accept from it.
 *
 * The compiler is trusted more than a builder — it is the other half of the same
 * product — but not so much that a bug in it should be able to write outside the tree,
 * which is why the path rule is enforced in both directions.
 */
#[CoversClass(CompilerRequest::class)]
final class CompilerRequestTest extends TestCase
{
    public function testACompiledSpecRoundTrips(): void
    {
        $schema = ['entities' => ['Post' => ['name' => 'Post']], 'types' => []];

        $decoded = CompilerRequest::decode(Envelope::fromJson((string) json_encode(
            CompilerRequest::encode($schema, ['php' => [new GeneratedFile('manifest.php', "return [];\n")]]),
        )));

        self::assertSame($schema, $decoded->schema);
        self::assertCount(1, $decoded->contributedTo('php'));
        self::assertSame('manifest.php', $decoded->contributedTo('php')[0]->relativePath);
    }

    public function testATargetWithNoContributionsGetsAnEmptyList(): void
    {
        // Through JSON, as it always is: `encode()` writes `files` as an object and
        // only the decode turns it back into something PHP calls an array.
        $decoded = CompilerRequest::decode(Envelope::fromJson((string) json_encode(
            CompilerRequest::encode(['entities' => []], []),
        )));

        self::assertSame([], $decoded->contributedTo('ts'));
    }

    public function testNoContributionsSurvivesAsAnObjectRatherThanAList(): void
    {
        // The same `[]` ambiguity that bites the config block: an empty PHP array
        // encodes as a JSON list, and the far side is typed for an object.
        $json = (string) json_encode(CompilerRequest::encode(['entities' => []], []));

        self::assertStringContainsString('"files":{}', $json);
    }

    public function testARequestWithNoSchemaIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/carries no schema/');

        CompilerRequest::decode([
            'elephentity' => Envelope::VERSION,
            'irVersion' => Envelope::IR_VERSION,
        ]);
    }

    public function testAContributedPathThatEscapesTheTreeIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/escapes the output directory/');

        CompilerRequest::decode([
            'elephentity' => Envelope::VERSION,
            'irVersion' => Envelope::IR_VERSION,
            'schema' => [],
            'files' => ['php' => [['path' => '../escape.php', 'body' => 'x']]],
        ]);
    }

    public function testAMalformedContributionIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/must be an object with "path" and "body"/');

        CompilerRequest::decode([
            'elephentity' => Envelope::VERSION,
            'irVersion' => Envelope::IR_VERSION,
            'schema' => [],
            'files' => ['php' => ['just-a-string']],
        ]);
    }

    public function testTheVersionGateRunsBeforeThePayloadIsRead(): void
    {
        // The whole reason the versions sit outside the payload: an unreadable schema
        // from a version this build does not speak should be refused for the version,
        // not stumbled over while parsing.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/IR version mismatch/');

        CompilerRequest::decode([
            'elephentity' => Envelope::VERSION,
            'irVersion' => '99.0',
            'schema' => 'not even an object',
        ]);
    }
}
