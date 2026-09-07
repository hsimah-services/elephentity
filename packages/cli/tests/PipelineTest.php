<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\Command\CheckCommand;
use Eleph\Cli\Command\FmtCommand;
use Eleph\Cli\Command\GenerateCommand;
use Eleph\Cli\Command\ValidateCommand;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The whole pipeline, end to end, on a real spec directory.
 *
 * Every layer is unit-tested in its own package, which proves each is correct and
 * proves nothing about them being wired together. This runs the four gates in the
 * order a project would — fmt, validate, generate, check — and then asserts the
 * gates are stable: regenerating changes nothing, and conformance holds against the
 * classes actually on disk.
 */
#[CoversNothing]
final class PipelineTest extends TestCase
{
    private string $project;

    /**
     * A namespace per test, because PHP loads a class once per process: two tests
     * generating the same class name would have the second silently inspecting the
     * first's code. The same constraint is why `eleph check` must be a one-shot
     * process rather than something a long-lived worker calls repeatedly.
     */
    private string $namespace;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));

        $this->project = sys_get_temp_dir() . '/eleph-pipeline-' . $suffix;
        $this->namespace = 'PipelineFixture' . $suffix . '\\Elephentity';

        mkdir($this->project . '/spec', 0o775, true);

        foreach (['entities', 'patterns', 'types'] as $directory) {
            $this->copy(
                __DIR__ . '/../../schema/tests/fixtures/valid/' . $directory,
                $this->project . '/spec/' . $directory,
            );
        }

        copy(
            __DIR__ . '/../../schema/tests/fixtures/valid/project.yml',
            $this->project . '/spec/project.yml',
        );

        file_put_contents($this->project . '/eleph.json', json_encode([
            'spec' => 'spec',
            'targets' => [
                'php' => [
                    'output' => 'generated',
                    'namespace' => $this->namespace,
                    'typeNamespace' => 'PipelineFixture\\Type',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testTheFourGatesPassInOrderOnACleanProject(): void
    {
        self::assertSame(Command::SUCCESS, $this->exec(new FmtCommand()));
        self::assertSame(Command::SUCCESS, $this->exec(new ValidateCommand(), ['spec' => $this->project . '/spec']));
        self::assertSame(Command::SUCCESS, $this->exec(new GenerateCommand()));

        // Conformance needs the classes to exist, so it runs after generation.
        self::assertSame(Command::SUCCESS, $this->exec(new CheckCommand()));
    }

    public function testGenerationIsIdempotent(): void
    {
        $this->exec(new GenerateCommand());

        // The gate the whole locked-file design rests on: a second run changes nothing,
        // so any difference in CI is a real difference.
        self::assertSame(Command::SUCCESS, $this->exec(new GenerateCommand(), ['--check' => true]));
    }

    public function testEditingAGeneratedFileFailsTheDriftGate(): void
    {
        $this->exec(new GenerateCommand());

        $post = $this->project . '/generated/Post/Post.php';
        file_put_contents(
            $post,
            str_replace('return $this->title;', 'return strtoupper($this->title);', (string) file_get_contents($post)),
        );

        $tester = $this->tester(new GenerateCommand(), ['--check' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Hand-edited', $tester->getDisplay());
    }

    public function testRenamingAnAccessorFailsConformanceEvenThoughTheFileStillParses(): void
    {
        // Drift detection and conformance answer different questions: this file is
        // valid PHP and would load happily, but the API it backs no longer resolves.
        $this->exec(new GenerateCommand());

        $post = $this->project . '/generated/Post/Post.php';
        file_put_contents(
            $post,
            str_replace('function getTitle(', 'function getHeadline(', (string) file_get_contents($post)),
        );

        $tester = $this->tester(new CheckCommand());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Post.title resolves via', $tester->getDisplay());
    }

    public function testTheGraphQLManifestIsGeneratedAlongsideTheClasses(): void
    {
        $this->exec(new GenerateCommand());

        self::assertFileExists($this->project . '/generated/graphql-manifest.php');
        self::assertFileExists($this->project . '/generated/Post/PostHydrator.php');
        self::assertFileExists($this->project . '/generated/Post/Contract/PostPriceVerifier.php');
    }

    public function testNarrowingToAConfiguredTargetStillGeneratesIt(): void
    {
        self::assertSame(Command::SUCCESS, $this->exec(new GenerateCommand(), ['--targets' => 'php']));
        self::assertFileExists($this->project . '/generated/Post/Post.php');
    }

    public function testAnUnknownTargetIsRefusedRatherThanIgnored(): void
    {
        // Silently generating nothing looks exactly like a target with nothing to do,
        // so a typo has to fail loudly and say what the project actually configures.
        $tester = $this->tester(new GenerateCommand(), ['--targets' => 'rust']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Unknown target(s): rust', $tester->getDisplay());
        self::assertStringContainsString('php', $tester->getDisplay());
        self::assertFileDoesNotExist($this->project . '/generated/Post/Post.php');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exec(Command $command, array $input = []): int
    {
        return $this->tester($command, $input)->getStatusCode();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function tester(Command $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($command);

        $tester->execute([...$input, ...($command instanceof ValidateCommand ? [] : ['--project' => $this->project])]);

        return $tester;
    }

    private function copy(string $from, string $to): void
    {
        mkdir($to, 0o775, true);

        foreach ((array) glob($from . '/*.yml') as $file) {
            if (is_string($file)) {
                copy($file, $to . '/' . basename($file));
            }
        }
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
