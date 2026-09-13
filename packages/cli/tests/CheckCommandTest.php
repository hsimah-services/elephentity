<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\ClassMap;
use Eleph\Cli\Command\CheckCommand;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `eleph check` no longer compiles a spec or asks any builder to describe itself — it
 * walks the generated tree for a `verify.php` in every integration's own directory,
 * loads each, and renders what it says. `PipelineTest` proves the real WPGraphQL
 * verifier end to end; this proves the mechanism itself against verifiers written by
 * hand, including the ones no real builder should ever produce.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/eleph-check-' . bin2hex(random_bytes(6));

        mkdir($this->root . '/generated', 0o775, true);

        $this->stubCodegen();
        $this->writeConfig();
        $this->writeClassMap();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testATreeWithNoVerifiersSucceedsQuietly(): void
    {
        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testInfoAndWarningSignalsRenderAndPass(): void
    {
        $this->writeVerifier('acme', <<<'PHP'
            <?php

            return new class implements \Eleph\Runtime\Conformance\Verifier {
                public function verify(\Closure $classFor): array
                {
                    return [
                        new \Eleph\Runtime\Conformance\Signal(\Eleph\Runtime\Conformance\Severity::Warning, 'a soft finding'),
                        new \Eleph\Runtime\Conformance\Signal(\Eleph\Runtime\Conformance\Severity::Info, 'cannot check everything'),
                    ];
                }
            };
            PHP);

        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('a soft finding', $tester->getDisplay());
        self::assertStringContainsString('cannot check everything', $tester->getDisplay());
    }

    public function testAnErrorSignalFailsTheCommand(): void
    {
        $this->writeVerifier('acme', <<<'PHP'
            <?php

            return new class implements \Eleph\Runtime\Conformance\Verifier {
                public function verify(\Closure $classFor): array
                {
                    return [new \Eleph\Runtime\Conformance\Signal(\Eleph\Runtime\Conformance\Severity::Error, 'a real problem')];
                }
            };
            PHP);

        $tester = $this->check();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('a real problem', $tester->getDisplay());
    }

    public function testTheClassLookupReachesTheVerifier(): void
    {
        $this->writeVerifier('acme', <<<'PHP'
            <?php

            return new class implements \Eleph\Runtime\Conformance\Verifier {
                public function verify(\Closure $classFor): array
                {
                    return [new \Eleph\Runtime\Conformance\Signal(
                        \Eleph\Runtime\Conformance\Severity::Info,
                        'Post resolves to ' . $classFor('Post'),
                    )];
                }
            };
            PHP);

        $tester = $this->check();

        self::assertStringContainsString('Post resolves to Fixture\\Post\\Post', $tester->getDisplay());
    }

    public function testAVerifierNamingAMissingClassFailsSayingToRegenerate(): void
    {
        $this->writeVerifier('acme', "<?php\n\nreturn new \\Totally\\Missing\\Verifier();\n");

        $tester = $this->check();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Run `eleph generate`', $tester->getDisplay());
    }

    public function testAFileThatDoesNotReturnAVerifierFailsSayingToRegenerate(): void
    {
        $this->writeVerifier('acme', "<?php\n\nreturn 'not a verifier';\n");

        $tester = $this->check();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('did not return a verifier', $tester->getDisplay());
        self::assertStringContainsString('Run `eleph generate`', $tester->getDisplay());
    }

    private function check(): CommandTester
    {
        $tester = new CommandTester(new CheckCommand());
        $tester->execute(['--project' => $this->root]);

        return $tester;
    }

    private function writeVerifier(string $integration, string $code): void
    {
        $directory = $this->root . '/generated/' . $integration;
        mkdir($directory, 0o775, true);
        file_put_contents($directory . '/verify.php', $code);
    }

    private function writeClassMap(): void
    {
        file_put_contents($this->root . '/generated/' . ClassMap::PATH, <<<'PHP'
            <?php

            return [
                'entities' => ['Post' => 'Fixture\Post\Post'],
                'classes' => [],
            ];

            PHP);
    }

    private function writeConfig(): void
    {
        file_put_contents($this->root . '/eleph.json', json_encode([
            'spec' => 'spec',
            'codegen' => 'eleph-codegen',
            'targets' => [
                'php' => ['output' => 'generated'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A stand-in orchestrator that answers only the one question `eleph check` still
     * asks it: where the PHP target writes. It resolves builders for nothing, which is
     * the point.
     */
    private function stubCodegen(): void
    {
        $path = $this->root . '/eleph-codegen';

        file_put_contents($path, "#!/usr/bin/env php\n<?php\necho '{\"targets\":{\"php\":{\"output\":\"generated\"}}}';\n");
        chmod($path, 0o775);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
