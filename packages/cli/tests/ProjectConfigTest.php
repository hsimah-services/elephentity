<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\ProjectConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * eleph.json is the first thing every command reads, and Elephentity reads two keys of
 * it: where the specs are, and where the generator is if it is somewhere unusual.
 *
 * The `targets` block is deliberately not validated here. `eleph-codegen` owns it, and
 * a second parser would eventually disagree with the first about something small.
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

    public function testTheSpecDirectoryIsRead(): void
    {
        $config = $this->load([
            'spec' => 'spec',
            'targets' => ['php' => ['output' => 'generated', 'builder' => 'eleph-gen-php']],
        ]);

        self::assertSame('spec', $config->specDirectory);
        self::assertNull($config->codegen);
    }

    public function testAProjectCanSayWhereItsGeneratorIs(): void
    {
        $config = $this->load(['spec' => 'spec', 'codegen' => 'tools/eleph-codegen']);

        self::assertSame('tools/eleph-codegen', $config->codegen);
    }

    public function testAConfigWithNoSpecIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/"spec" must be a non-empty string/');

        $this->load(['targets' => ['php' => ['output' => 'generated']]]);
    }

    public function testATargetsBlockIsCarriedPastWithoutBeingJudged(): void
    {
        // Nonsense to Elephentity, and not Elephentity's to reject: the generator
        // parses this block and reports on it, which is why there is only one parser.
        $config = $this->load(['spec' => 'spec', 'targets' => 'not even an object']);

        self::assertSame('spec', $config->specDirectory);
    }

    public function testAFileThatIsNotJsonSaysSo(): void
    {
        file_put_contents($this->directory . '/' . ProjectConfig::FILENAME, '{ nope');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not valid JSON/');

        ProjectConfig::load($this->directory);
    }

    public function testAMissingFileNamesWhatItNeeded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/It needs "spec" and a "targets" block/');

        ProjectConfig::load($this->directory);
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
