<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Schema\Wire\BuilderEnvelope;
use Eleph\Schema\Wire\IrCodec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The builder binary itself, asked the question `Installed` actually sends: does
 * `eleph-gen-wordpress describe` answer `provides.patterns.Taxonomy`, over the same
 * stdin/stdout wire a real `eleph generate` uses, rather than through a unit calling
 * the code inside it directly.
 */
final class DescribeTest extends TestCase
{
    public function testDescribeAnswersTheTaxonomyPattern(): void
    {
        $response = json_decode($this->describe(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($response);
        self::assertSame(BuilderEnvelope::VERSION, $response['elephentity'] ?? null);

        $provides = $response['provides'] ?? null;
        self::assertIsArray($provides);

        $patterns = $provides['patterns'] ?? null;
        self::assertIsArray($patterns);
        self::assertArrayHasKey('Taxonomy', $patterns);

        $taxonomy = $patterns['Taxonomy'];
        self::assertIsArray($taxonomy);
        self::assertSame('Taxonomy', $taxonomy['pattern'] ?? null);

        $requires = $taxonomy['requires'] ?? null;
        self::assertIsArray($requires);
        self::assertSame('wordpress', $requires['driver'] ?? null);

        self::assertSame(['wordpress'], $provides['drivers'] ?? null);
    }

    private function describe(): string
    {
        $request = json_encode([
            'elephentity' => BuilderEnvelope::VERSION,
            'irVersion' => IrCodec::VERSION,
            'request' => BuilderEnvelope::REQUEST_DESCRIBE,
        ], JSON_THROW_ON_ERROR);

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../bin/eleph-gen-wordpress'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (false === $process) {
            throw new RuntimeException('Could not start eleph-gen-wordpress.');
        }

        fwrite($pipes[0], $request);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        if (0 !== $exit) {
            throw new RuntimeException(sprintf('eleph-gen-wordpress exited %d: %s', $exit, (string) $stderr));
        }

        return (string) $stdout;
    }
}
