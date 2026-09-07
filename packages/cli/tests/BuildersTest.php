<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\Builders;
use Eleph\Cli\TargetConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Elephentity resolves builders and never fetches them, so the whole surface is: find
 * it, or say clearly where you looked.
 */
#[CoversClass(Builders::class)]
final class BuildersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/eleph-builders-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/tools/builders', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->root . '/tools/builders/eleph-gen-ts',
            $this->root . '/custom/gen',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ([
            $this->root . '/tools/builders',
            $this->root . '/tools',
            $this->root . '/custom',
            $this->root,
        ] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testABuilderInTheProjectsBuildersDirectoryIsFound(): void
    {
        $path = $this->root . '/tools/builders/eleph-gen-ts';
        $this->executable($path);

        self::assertSame([$path], $this->resolve('eleph-gen-ts'));
    }

    public function testAnExplicitPathIsTakenAtItsWord(): void
    {
        mkdir($this->root . '/custom', 0o775, true);
        $this->executable($this->root . '/custom/gen');

        self::assertSame([$this->root . '/custom/gen'], $this->resolve('custom/gen'));
    }

    public function testAMissingBuilderNamesEverywhereItLooked(): void
    {
        try {
            $this->resolve('eleph-gen-ts');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tools/builders/eleph-gen-ts', $exception->getMessage());
            self::assertStringContainsString('anywhere on PATH', $exception->getMessage());
            self::assertStringContainsString('does not fetch builders', $exception->getMessage());

            return;
        }

        self::fail('A missing builder should not resolve.');
    }

    public function testABuilderThatIsNotExecutableSaysSo(): void
    {
        // The single most likely thing to go wrong after checking a builder out.
        $path = $this->root . '/tools/builders/eleph-gen-ts';
        file_put_contents($path, "#!/usr/bin/env node\n");
        chmod($path, 0o644);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not executable/');

        $this->resolve('eleph-gen-ts');
    }

    /**
     * @return list<string>
     */
    private function resolve(string $builder): array
    {
        return (new Builders($this->root))->resolve(
            new TargetConfig('ts', 'web/generated', [], $builder),
        );
    }

    private function executable(string $path): void
    {
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0o755);
    }
}
