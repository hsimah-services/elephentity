<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests\Protocol;

use Eleph\Codegen\Protocol\Envelope;
use Eleph\Codegen\Protocol\ProtocolException;
use Eleph\Codegen\Signing\HeaderStyle;
use Eleph\Codegen\TargetRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The envelope is the one shape both sides must agree on before anything else can be
 * read, so its tests are mostly about refusing things.
 *
 * Responses are written out as literal arrays rather than produced by an encoder here.
 * There is no encoder on this side — a builder wrote them, possibly in another language
 * — and a test that fed this class its own output would prove the two halves agree with
 * themselves rather than with the format.
 */
#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    private const SCHEMA = ['project' => ['name' => 'Demo'], 'entities' => [], 'types' => []];

    public function testARequestCarriesItsVersionsOutsideThePayload(): void
    {
        $encoded = Envelope::encodeRequest(
            'php',
            TargetRequest::of('generated', ['namespace' => 'App']),
            self::SCHEMA,
        );

        // A builder reads these and decides whether to proceed without having parsed an
        // entity. Inside the payload they would be useless for that.
        self::assertSame(Envelope::VERSION, $encoded['elephentity']);
        self::assertSame(Envelope::IR_VERSION, $encoded['irVersion']);
        self::assertSame('php', $encoded['target']);
        self::assertSame('generated', $encoded['outputDirectory']);
    }

    public function testTheSchemaIsForwardedByteForByte(): void
    {
        // The orchestrator does not decode the IR, so anything it did to the payload
        // would be damage.
        $encoded = Envelope::encodeRequest('php', TargetRequest::of('generated', []), self::SCHEMA);

        self::assertSame(self::SCHEMA, $encoded['schema']);
    }

    public function testAnEmptyConfigSurvivesAsAnObjectRatherThanAList(): void
    {
        // json_encode turns an empty PHP array into `[]`, which decodes as a list and
        // would then fail the "config must be an object" check on the far side.
        $json = Envelope::toJson(Envelope::encodeRequest('php', TargetRequest::of('generated', []), []));

        self::assertStringContainsString('"config":{}', $json);
    }

    public function testAResponseIsRead(): void
    {
        $decoded = Envelope::decodeResponse($this->response([
            'files' => [['path' => 'Post/Post.php', 'body' => "namespace App;\n"]],
        ]));

        self::assertSame(['php'], $decoded->extensions);
        self::assertSame(HeaderStyle::Php, $decoded->headerStyle);
        self::assertCount(1, $decoded->files);
        self::assertSame('Post/Post.php', $decoded->files[0]->relativePath);
        self::assertSame("namespace App;\n", $decoded->files[0]->body);
    }

    public function testABuildersErrorsSurviveInsteadOfItsFiles(): void
    {
        $decoded = Envelope::decodeResponse($this->response([
            'errors' => ['no "outDir" setting', 'unknown "style"'],
        ]));

        self::assertFalse($decoded->isSuccess());
        self::assertSame(['no "outDir" setting', 'unknown "style"'], $decoded->errors);
        self::assertSame([], $decoded->files);
    }

    public function testAResponseFromAnotherProtocolVersionIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Protocol version mismatch/');

        Envelope::decodeResponse($this->response(['elephentity' => 99]));
    }

    public function testAResponseFromAnotherIrVersionIsRefused(): void
    {
        // A stale builder that half-understands the IR emits subtly wrong code that this
        // program then signs. Not building is strictly better.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/IR version mismatch/');

        Envelope::decodeResponse($this->response(['irVersion' => '99.0']));
    }

    public function testAFilePathThatEscapesTheOutputDirectoryIsRefused(): void
    {
        // Nothing downstream would notice: the writer joins the path to the output
        // directory and writes wherever that lands.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/escapes the output directory/');

        Envelope::decodeResponse($this->response([
            'files' => [['path' => '../../etc/passwd', 'body' => 'x']],
        ]));
    }

    public function testAnUnknownHeaderStyleIsRefused(): void
    {
        // The header is a fixed number of lines per style and the digest skips it by
        // position, so a style this build cannot render is one it cannot verify either.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/header style "runes"/');

        Envelope::decodeResponse($this->response(['headerStyle' => 'runes']));
    }

    public function testAResponseThatIsNotJsonIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Not valid JSON/');

        Envelope::fromJson("warning: something\n");
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function response(array $overrides = []): array
    {
        return [
            'elephentity' => Envelope::VERSION,
            'irVersion' => Envelope::IR_VERSION,
            'headerStyle' => 'php',
            'extensions' => ['php'],
            'files' => [],
            'errors' => [],
            ...$overrides,
        ];
    }
}
