<?php

declare(strict_types=1);

namespace Eleph\Memory\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `bin/eleph-gen-memory` over the builder protocol, as `eleph-codegen` runs it: one JSON
 * request on stdin, one JSON response on stdout, and a nonzero exit with the reason on
 * stderr when the request is not one it can answer.
 */
#[CoversNothing]
final class MemoryBuilderTest extends TestCase
{
    public function testItDescribesItselfAsTheMemoryDriver(): void
    {
        [$status, $stdout] = $this->send(['elephentity' => 1, 'irVersion' => '1.2', 'request' => 'describe']);

        self::assertSame(0, $status);
        self::assertSame(
            ['elephentity' => 1, 'irVersion' => '1.2', 'provides' => ['drivers' => ['memory']]],
            json_decode($stdout, true),
        );
    }

    public function testGenerateWritesNothingForAMemoryProject(): void
    {
        [$status, $stdout] = $this->send($this->generate('memory'));

        self::assertSame(0, $status);
        $response = json_decode($stdout, true);
        self::assertIsArray($response);
        self::assertSame([], $response['files']);
        self::assertSame([], $response['errors']);
    }

    public function testGenerateSaysSoWhenTheProjectChoseAnotherDriver(): void
    {
        [$status, $stdout] = $this->send($this->generate('sqlite'));

        self::assertSame(0, $status);
        $response = json_decode($stdout, true);
        self::assertIsArray($response);
        self::assertSame([], $response['files']);
        self::assertIsArray($response['errors']);
        self::assertIsString($response['errors'][0] ?? null);
        self::assertStringContainsString('declares driver "sqlite"', $response['errors'][0]);
    }

    public function testAnotherIrVersionIsRefused(): void
    {
        [$status, , $stderr] = $this->send(['elephentity' => 1, 'irVersion' => '9.9', 'request' => 'describe']);

        self::assertSame(1, $status);
        self::assertStringContainsString('IR version mismatch', $stderr);
    }

    public function testNothingOnStdinIsSaidPlainly(): void
    {
        [$status, , $stderr] = $this->send(null);

        self::assertSame(1, $status);
        self::assertStringContainsString('Expected a request on stdin', $stderr);
    }

    /** @return array<string, mixed> */
    private function generate(string $driver): array
    {
        return [
            'elephentity' => 1,
            'irVersion' => '1.2',
            'request' => 'generate',
            'target' => 'memory',
            'config' => [],
            'schema' => ['project' => ['name' => 'Fixture', 'driver' => $driver], 'entities' => [], 'types' => []],
        ];
    }

    /**
     * @param array<string, mixed>|null $request
     *
     * @return array{int, string, string}
     */
    private function send(?array $request): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../bin/eleph-gen-memory'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        fwrite($pipes[0], null === $request ? '' : json_encode($request, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
