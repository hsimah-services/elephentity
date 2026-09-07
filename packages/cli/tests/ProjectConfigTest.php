<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\ProjectConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * eleph.json is the first thing every command reads, so a bad one has to fail with a
 * list of what is wrong rather than the first thing noticed.
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
                'php' => ['output' => 'generated', 'namespace' => 'App\\Entity'],
            ],
        ]);

        self::assertSame('spec', $config->specDirectory);
        self::assertSame('generated', $config->target('php')?->outputDirectory);
        self::assertNull($config->target('ts'));

        // Everything but "output" reaches the target untouched, including "output"
        // itself: the core reads it but has no business stripping it.
        self::assertSame('App\\Entity', $config->target('php')?->settings['namespace'] ?? null);
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
                'php' => ['output' => 'generated'],
                'ts' => ['output' => 'generated/'],
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
