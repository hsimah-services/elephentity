<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests\Protocol;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Protocol\Envelope;
use Eleph\Codegen\Protocol\ProtocolException;
use Eleph\Codegen\Signing\HeaderStyle;
use Eleph\Codegen\TargetRequest;
use Eleph\Codegen\TargetResponse;
use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Wire\IrCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The envelope is the one shape both sides must agree on before anything else can be
 * read, so its tests are mostly about refusing things.
 */
#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    public function testARequestRoundTrips(): void
    {
        $schema = new Schema(new ProjectDefinition('Demo', 'wordpress', 'project.yml'));
        $request = TargetRequest::of('generated', ['namespace' => 'App']);

        $incoming = Envelope::decodeRequest(
            Envelope::fromJson(Envelope::toJson(Envelope::encodeRequest('php', $request, $schema))),
        );

        self::assertSame('php', $incoming->target);
        self::assertSame('generated', $incoming->request->outputDirectory);
        self::assertSame(['namespace' => 'App'], $incoming->request->config);
        self::assertEquals($schema, $incoming->schema);
    }

    public function testAnEmptyConfigSurvivesAsAnObjectRatherThanAList(): void
    {
        // json_encode turns an empty PHP array into `[]`, which decodes as a list and
        // would then fail the "config must be an object" check on the far side.
        $schema = new Schema(new ProjectDefinition('Demo', 'wordpress', 'project.yml'));

        $json = Envelope::toJson(Envelope::encodeRequest('php', TargetRequest::of('generated', []), $schema));

        self::assertStringContainsString('"config":{}', $json);
        self::assertSame([], Envelope::decodeRequest(Envelope::fromJson($json))->request->config);
    }

    public function testARequestFromAnotherProtocolVersionIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Protocol version mismatch/');

        Envelope::decodeRequest(['elephentity' => 99, 'irVersion' => IrCodec::VERSION]);
    }

    public function testARequestFromAnotherIrVersionIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/IR version mismatch/');

        Envelope::decodeRequest(['elephentity' => Envelope::VERSION, 'irVersion' => '99.0']);
    }

    public function testAResponseRoundTrips(): void
    {
        $response = TargetResponse::ok(
            [new GeneratedFile('Post/Post.php', "namespace App;\n")],
            HeaderStyle::Php,
            ['php'],
        );

        $decoded = Envelope::decodeResponse(
            Envelope::fromJson(Envelope::toJson(Envelope::encodeResponse($response))),
        );

        self::assertSame(['php'], $decoded->extensions);
        self::assertSame(HeaderStyle::Php, $decoded->headerStyle);
        self::assertCount(1, $decoded->files);
        self::assertSame('Post/Post.php', $decoded->files[0]->relativePath);
    }

    public function testABuildersErrorsSurviveInsteadOfItsFiles(): void
    {
        $decoded = Envelope::decodeResponse(
            Envelope::fromJson(Envelope::toJson(Envelope::encodeResponse(
                TargetResponse::failed(['no "outDir" setting', 'unknown "style"']),
            ))),
        );

        self::assertFalse($decoded->isSuccess());
        self::assertSame(['no "outDir" setting', 'unknown "style"'], $decoded->errors);
    }

    public function testAFilePathThatEscapesTheOutputDirectoryIsRefused(): void
    {
        // Nothing downstream would notice: the writer joins the path to the output
        // directory and writes wherever that lands.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/escapes the output directory/');

        Envelope::decodeResponse([
            'elephentity' => Envelope::VERSION,
            'irVersion' => IrCodec::VERSION,
            'headerStyle' => 'php',
            'extensions' => ['php'],
            'files' => [['path' => '../../etc/passwd', 'body' => 'x']],
            'errors' => [],
        ]);
    }

    public function testAnUnknownHeaderStyleIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/header style "runes"/');

        Envelope::decodeResponse([
            'elephentity' => Envelope::VERSION,
            'irVersion' => IrCodec::VERSION,
            'headerStyle' => 'runes',
            'extensions' => [],
            'files' => [],
            'errors' => [],
        ]);
    }
}
