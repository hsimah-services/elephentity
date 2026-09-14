<?php

declare(strict_types=1);

namespace Eleph\Memory\Tests;

use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Wire\BuilderEnvelope;
use Eleph\Schema\Wire\IrCodec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `Installed` selects a driver by pooling `provides.drivers` from every target's
 * `describe` answer (already tested generically in `Eleph\Cli\Tests\InstalledTest`);
 * this proves the real binary actually answers it, over the real wire, so a project
 * can declare `driver: memory` and have something installed say it provides it.
 */
final class DescribeTest extends TestCase
{
    public function testDescribeAnswersTheMemoryDriver(): void
    {
        $response = json_decode($this->describe(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($response);

        $provides = $response['provides'] ?? null;
        self::assertIsArray($provides);
        self::assertSame(['memory'], $provides['drivers'] ?? null);
    }

    public function testGenerateSucceedsWithNothingToWrite(): void
    {
        $schema = new Schema(new ProjectDefinition('Test', 'memory', 'project.yml'));

        $request = json_encode([
            'elephentity' => BuilderEnvelope::VERSION,
            'irVersion' => IrCodec::VERSION,
            'request' => BuilderEnvelope::REQUEST_GENERATE,
            'schema' => IrCodec::encode($schema),
        ], JSON_THROW_ON_ERROR);

        $response = json_decode($this->runBuilder($request), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($response);
        self::assertSame([], $response['files'] ?? null);
        self::assertSame([], $response['errors'] ?? null);
    }

    public function testGenerateNamesTheMismatchWhenAnotherDriverIsConfigured(): void
    {
        $schema = new Schema(new ProjectDefinition('Test', 'wordpress', 'project.yml'));

        $request = json_encode([
            'elephentity' => BuilderEnvelope::VERSION,
            'irVersion' => IrCodec::VERSION,
            'request' => BuilderEnvelope::REQUEST_GENERATE,
            'schema' => IrCodec::encode($schema),
        ], JSON_THROW_ON_ERROR);

        $response = json_decode($this->runBuilder($request), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($response);
        self::assertSame([], $response['files'] ?? null);

        $errors = $response['errors'] ?? null;
        self::assertIsArray($errors);

        $message = $errors[0] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('declares driver "wordpress"', $message);
    }

    private function describe(): string
    {
        return $this->runBuilder(json_encode([
            'elephentity' => BuilderEnvelope::VERSION,
            'irVersion' => IrCodec::VERSION,
            'request' => BuilderEnvelope::REQUEST_DESCRIBE,
        ], JSON_THROW_ON_ERROR));
    }

    private function runBuilder(string $request): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../bin/eleph-gen-memory'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (false === $process) {
            throw new RuntimeException('Could not start eleph-gen-memory.');
        }

        fwrite($pipes[0], $request);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        if (0 !== $exit) {
            throw new RuntimeException(sprintf('eleph-gen-memory exited %d: %s', $exit, $stderr));
        }

        return $stdout;
    }
}
