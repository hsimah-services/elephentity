<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Tests;

use Eleph\Codegen\External\ExternalTarget;
use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\PhpTarget;
use Eleph\Codegen\TargetRequest;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The PHP target is reachable two ways — called in process, or run as a builder over
 * the protocol — and this is what stops those becoming two different generators.
 *
 * It is also the only test that exercises the wire format end to end against a real
 * subprocess: the schema is encoded, written to a pipe, decoded by another PHP process,
 * generated from, and sent back. Anything the codec loses shows up here as a diff.
 */
#[CoversNothing]
final class ExternalEquivalenceTest extends TestCase
{
    private const CONFIG = ['namespace' => 'App\\Elephentity', 'typeNamespace' => 'App\\Type'];

    public function testTheBuilderProducesExactlyWhatTheInProcessTargetDoes(): void
    {
        $schema = $this->schema();
        $request = TargetRequest::of('/tmp/eleph-not-written', self::CONFIG);

        $inProcess = (new PhpTarget())->generate($request, $schema);
        $external = $this->builder()->generate($request, $schema);

        self::assertSame([], $external->errors, 'The builder should have generated cleanly.');
        self::assertSame($inProcess->headerStyle, $external->headerStyle);
        self::assertSame($inProcess->extensions, $external->extensions);

        self::assertSame(
            $this->index($inProcess->files),
            $this->index($external->files),
            'The wire path generated different bytes from the in-process path.',
        );
    }

    public function testAResponseFromAnotherIrVersionIsRefused(): void
    {
        // A stale builder that half-understands the IR would generate subtly wrong code
        // that the core then signs, so the gate fails the build rather than warning.
        $builder = new ExternalTarget('php', [PHP_BINARY, '-r', <<<'CODE'
            echo json_encode([
                'elephentity' => 1,
                'irVersion' => '99.0',
                'headerStyle' => 'php',
                'extensions' => ['php'],
                'files' => [],
                'errors' => [],
            ]);
            CODE]);

        $response = $builder->generate(
            TargetRequest::of('/tmp/eleph-not-written', self::CONFIG),
            $this->schema(),
        );

        self::assertNotSame([], $response->errors);
        self::assertStringContainsString('IR version mismatch', $response->errors[0]);
    }

    public function testABuilderThatCrashesReportsItsStderr(): void
    {
        $builder = new ExternalTarget('php', [PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);']);

        $response = $builder->generate(
            TargetRequest::of('/tmp/eleph-not-written', self::CONFIG),
            $this->schema(),
        );

        self::assertNotSame([], $response->errors);
        self::assertStringContainsString('exited 3', $response->errors[0]);
        self::assertStringContainsString('boom', $response->errors[0]);
    }

    private function builder(): ExternalTarget
    {
        return new ExternalTarget('php', [PHP_BINARY, dirname(__DIR__) . '/bin/eleph-gen-php']);
    }

    /**
     * @param list<GeneratedFile> $files
     *
     * @return array<string, string>
     */
    private function index(array $files): array
    {
        $indexed = [];

        foreach ($files as $file) {
            $indexed[$file->relativePath] = $file->body;
        }

        ksort($indexed);

        return $indexed;
    }

    private function schema(): Schema
    {
        $compiled = (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(dirname(__DIR__, 2) . '/schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        return $compiled->schema();
    }
}
