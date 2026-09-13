<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * `tools/check-architecture.php` is a standalone script rather than a class in a
 * package, so this drives it as a subprocess against a throwaway fixture tree — the
 * same shape as `packages/`, built fresh per test — instead of importing functions
 * from it. It takes the fixture root as an optional first argument for exactly this.
 *
 * Both new rules exist because the historical violation they name was invisible to
 * the existing symbol check: `WORDPRESS_HANDLE_LIMIT` is a constant name and a string
 * literal, and `use Eleph\WPGraphQL\...` is an import, and neither is a bare `wp_*`
 * identifier.
 */
final class CheckArchitectureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/eleph-arch-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAWordpressNamedConstantFailsTheBuild(): void
    {
        $this->writeCoreFile('schema', 'src/SemanticValidator.php', <<<'PHP'
            <?php

            final class SemanticValidator
            {
                private const WORDPRESS_HANDLE_LIMIT = 20;
            }

            PHP);

        $result = $this->runCheck();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('WORDPRESS_HANDLE_LIMIT', $result['stderr']);
        self::assertStringContainsString('WordPress vocabulary', $result['stderr']);
    }

    public function testAWordpressStringLiteralFailsTheBuild(): void
    {
        $this->writeCoreFile('schema', 'src/Foo.php', <<<'PHP'
            <?php

            final class Foo
            {
                public function isWordPress(string $driver): bool
                {
                    return 'wordpress' === $driver;
                }
            }

            PHP);

        $result = $this->runCheck();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('WordPress vocabulary', $result['stderr']);
    }

    public function testImportingAPlatformPackageFromCoreFailsTheBuild(): void
    {
        $this->writeCoreFile('cli', 'src/Command/CheckCommand.php', <<<'PHP'
            <?php

            namespace Eleph\Cli\Command;

            use Eleph\WPGraphQL\Conformance\ConformanceChecker;

            final class CheckCommand
            {
            }

            PHP);

        $result = $this->runCheck();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Eleph\\WPGraphQL\\Conformance\\ConformanceChecker', $result['stderr']);
        self::assertStringContainsString('platform package', $result['stderr']);
    }

    public function testProseMentioningWordPressInADocCommentStaysLegal(): void
    {
        $this->writeCoreFile('schema', 'src/Foo.php', <<<'PHP'
            <?php

            /**
             * A note about the wordpress driver and Eleph\WordPress\Foo, in prose only —
             * naming both here is not the same as depending on either.
             */
            final class Foo
            {
            }

            PHP);

        $result = $this->runCheck();

        self::assertSame(0, $result['exit'], $result['stderr']);
    }

    public function testATestNamingTheRealDriverStaysLegal(): void
    {
        // A test exercising driver-agnostic code needs a real driver name to exercise
        // it with, and "wordpress" is the one that exists. That is data, not a leak.
        $this->writeCoreFile('schema', 'tests/FooTest.php', <<<'PHP'
            <?php

            final class FooTest
            {
                public function testDriver(): void
                {
                    $driver = 'wordpress';
                }
            }

            PHP);

        $result = $this->runCheck();

        self::assertSame(0, $result['exit'], $result['stderr']);
    }

    private function writeCoreFile(string $package, string $relative, string $contents): void
    {
        $path = $this->root . '/packages/' . $package . '/' . $relative;
        mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $contents);
    }

    /**
     * @return array{exit: int, stderr: string}
     */
    private function runCheck(): array
    {
        $script = dirname(__DIR__) . '/check-architecture.php';

        $process = proc_open(
            [PHP_BINARY, $script, $this->root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process, 'Could not run check-architecture.php.');

        // Drained rather than closed: closing an unread pipe while the child still has
        // output queued for it is a broken pipe on the child's side, not an empty read
        // on this one.
        fread($pipes[1], 1024 * 1024);
        fclose($pipes[1]);

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return ['exit' => $exit, 'stderr' => $stderr];
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
