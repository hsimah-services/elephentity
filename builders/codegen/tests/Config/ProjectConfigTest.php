<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests\Config;

use Eleph\Codegen\Config\ProjectConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * eleph.json's `targets` block has exactly one parser, and this is it.
 *
 * A bad one has to fail with a list of what is wrong rather than the first thing
 * noticed: a file missing three keys should take one run to fix, not three.
 */
#[CoversClass(ProjectConfig::class)]
final class ProjectConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/eleph-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        $path = $this->directory . '/' . ProjectConfig::FILENAME;

        if (is_file($path)) {
            unlink($path);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testATargetsBlockIsRead(): void
    {
        $config = $this->load([
            'spec' => 'spec',
            'targets' => [
                'php' => [
                    'output' => 'generated',
                    'builder' => 'eleph-gen-php',
                    'namespace' => 'App\\Entity',
                ],
            ],
        ]);

        $php = $config->target('php');

        self::assertNotNull($php);
        self::assertSame('generated', $php->outputDirectory);
        self::assertSame('eleph-gen-php', $php->builder);
        self::assertNull($config->target('ts'));

        // Everything reaches the target untouched, including the keys read here: they
        // are read, not consumed, and stripping them would be this program deciding
        // what a target is allowed to know about itself.
        self::assertSame('App\\Entity', $php->settings['namespace'] ?? null);
    }

    public function testATargetWithNoBuilderIsRefused(): void
    {
        // Nothing is built in. A target with no builder is a target nothing can
        // produce, and the config is the cheapest place to say so.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Target "php" must set "builder"/');

        $this->load(['spec' => 'spec', 'targets' => ['php' => ['output' => 'generated']]]);
    }

    public function testTwoTargetsSharingAnOutputDirectoryAreRefused(): void
    {
        // Generating one target deletes what it does not produce, so a shared directory
        // means `--targets php` would sweep away the ts tree.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/php and ts all write to "generated"/');

        $this->load([
            'spec' => 'spec',
            'targets' => [
                'php' => ['output' => 'generated', 'builder' => 'eleph-gen-php'],
                'ts' => ['output' => 'generated/', 'builder' => 'eleph-gen-ts'],
            ],
        ]);
    }

    public function testEveryProblemIsReportedTogether(): void
    {
        try {
            $this->load(['targets' => ['php' => ['output' => '']]]);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('"spec" must be a non-empty string', $exception->getMessage());
            self::assertStringContainsString('Target "php" must set "output"', $exception->getMessage());

            return;
        }

        self::fail('A config missing both "spec" and a usable output should not load.');
    }

    public function testAProjectWithNoTargetsIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/"targets" must be an object/');

        $this->load(['spec' => 'spec', 'targets' => []]);
    }

    public function testTheBuildersDirectoryCanBeMoved(): void
    {
        $config = $this->load([
            'spec' => 'spec',
            'builders' => 'bin/builders',
            'targets' => ['php' => ['output' => 'generated', 'builder' => 'eleph-gen-php']],
        ]);

        self::assertSame('bin/builders', $config->buildersDirectory);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function load(array $data): ProjectConfig
    {
        file_put_contents(
            $this->directory . '/' . ProjectConfig::FILENAME,
            json_encode($data, JSON_THROW_ON_ERROR),
        );

        return ProjectConfig::load($this->directory);
    }
}
