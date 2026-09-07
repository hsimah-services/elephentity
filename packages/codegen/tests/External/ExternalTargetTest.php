<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests\External;

use Eleph\Codegen\External\ExternalTarget;
use Eleph\Codegen\Protocol\Envelope;
use Eleph\Codegen\Signing\HeaderStyle;
use Eleph\Codegen\TargetRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A builder is somebody else's program, so every test here is about what happens when
 * it misbehaves.
 *
 * The stubs are `php -r` one-liners rather than fixture files: what is under test is
 * the exchange, and a builder that exists only as the string of its own source is the
 * clearest way to say that the host does not care what produced the bytes.
 */
#[CoversClass(ExternalTarget::class)]
final class ExternalTargetTest extends TestCase
{
    public function testAWellBehavedBuilderReturnsItsFiles(): void
    {
        $builder = $this->stub(sprintf(<<<'CODE'
            echo json_encode([
                'elephentity' => %d,
                'irVersion' => %s,
                'headerStyle' => 'line-comment',
                'extensions' => ['ts'],
                'files' => [['path' => 'post/Post.ts', 'body' => "export interface Post {}\n"]],
                'errors' => [],
            ]);
            CODE, Envelope::VERSION, var_export(Envelope::IR_VERSION, true)));

        $response = $builder->generate(TargetRequest::of('/tmp/eleph-not-written', []), []);

        self::assertSame([], $response->errors);
        self::assertSame(HeaderStyle::LineComment, $response->headerStyle);
        self::assertSame(['ts'], $response->extensions);
        self::assertCount(1, $response->files);
        self::assertSame('post/Post.ts', $response->files[0]->relativePath);
    }

    public function testTheSchemaReachesTheBuilderUntouched(): void
    {
        // The host forwards an IR it never decodes. Echoing the payload back proves the
        // bytes it was handed are the bytes that crossed the pipe.
        $builder = $this->stub(sprintf(<<<'CODE'
            $request = json_decode(file_get_contents('php://stdin'), true);
            echo json_encode([
                'elephentity' => %d,
                'irVersion' => %s,
                'headerStyle' => 'line-comment',
                'extensions' => ['txt'],
                'files' => [['path' => 'echo.txt', 'body' => json_encode($request['schema'])]],
                'errors' => [],
            ]);
            CODE, Envelope::VERSION, var_export(Envelope::IR_VERSION, true)));

        $schema = ['entities' => ['Post' => ['name' => 'Post']], 'types' => []];
        $response = $builder->generate(TargetRequest::of('/tmp/eleph-not-written', []), $schema);

        self::assertSame([], $response->errors);
        self::assertSame(json_encode($schema), $response->files[0]->body);
    }

    public function testAResponseFromAnotherIrVersionIsRefused(): void
    {
        // A stale builder that half-understands the IR would generate subtly wrong code
        // that the core then signs, so the gate fails the build rather than warning.
        $builder = $this->stub(sprintf(<<<'CODE'
            echo json_encode([
                'elephentity' => %d,
                'irVersion' => '99.0',
                'headerStyle' => 'php',
                'extensions' => ['php'],
                'files' => [],
                'errors' => [],
            ]);
            CODE, Envelope::VERSION));

        $response = $builder->generate(TargetRequest::of('/tmp/eleph-not-written', []), []);

        self::assertNotSame([], $response->errors);
        self::assertStringContainsString('IR version mismatch', $response->errors[0]);
    }

    public function testABuilderThatCrashesReportsItsStderr(): void
    {
        $builder = $this->stub('fwrite(STDERR, "boom"); exit(3);');

        $response = $builder->generate(TargetRequest::of('/tmp/eleph-not-written', []), []);

        self::assertNotSame([], $response->errors);
        self::assertStringContainsString('exited 3', $response->errors[0]);
        self::assertStringContainsString('boom', $response->errors[0]);
    }

    public function testABuilderThatWritesRubbishToStdoutIsRefused(): void
    {
        // stdout is the response and nothing else, so a stray print is a protocol error
        // rather than something to be parsed around.
        $builder = $this->stub('echo "warning: something\n";');

        $response = $builder->generate(TargetRequest::of('/tmp/eleph-not-written', []), []);

        self::assertNotSame([], $response->errors);
        self::assertStringContainsString('sent something unusable', $response->errors[0]);
    }

    public function testABuilderThatNamesItsWayOutOfTheOutputDirectoryIsRefused(): void
    {
        $builder = $this->stub(sprintf(<<<'CODE'
            echo json_encode([
                'elephentity' => %d,
                'irVersion' => %s,
                'headerStyle' => 'php',
                'extensions' => ['php'],
                'files' => [['path' => '../../etc/passwd', 'body' => 'nope']],
                'errors' => [],
            ]);
            CODE, Envelope::VERSION, var_export(Envelope::IR_VERSION, true)));

        $response = $builder->generate(TargetRequest::of('/tmp/eleph-not-written', []), []);

        self::assertNotSame([], $response->errors);
        self::assertStringContainsString('escapes the output directory', $response->errors[0]);
    }

    private function stub(string $code): ExternalTarget
    {
        return new ExternalTarget('stub', [PHP_BINARY, '-r', $code]);
    }
}
