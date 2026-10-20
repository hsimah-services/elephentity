<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Tests;

use Eleph\Gen\Php\Check\TreeCheck;
use Eleph\Runtime\Conformance\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TreeCheck::class)]
final class CheckTest extends TestCase
{
    private string $tree;

    protected function setUp(): void
    {
        $this->tree = sys_get_temp_dir() . '/eleph-check-' . bin2hex(random_bytes(6));
        mkdir($this->tree . '/Post', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tree . '/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->tree . '/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }

        rmdir($this->tree);
    }

    public function testATreeWithoutAClassMapIsAnErrorNotAPass(): void
    {
        $signals = TreeCheck::run($this->tree);

        self::assertCount(1, $signals);
        self::assertSame(Severity::Error, $signals[0]->severity);
        self::assertStringContainsString('No class-map.php', $signals[0]->message);
    }

    public function testAMalformedClassMapIsAnError(): void
    {
        file_put_contents($this->tree . '/class-map.php', "<?php return ['entities' => 3];");

        $signals = TreeCheck::run($this->tree);

        self::assertSame(Severity::Error, $signals[0]->severity);
        self::assertStringContainsString('carries no "entities" map', $signals[0]->message);
    }

    public function testATreeWithNoVerifiersIsQuietAndPasses(): void
    {
        $this->classMap();

        self::assertSame([], TreeCheck::run($this->tree));
    }

    public function testEveryVerifiersSignalsAreCollected(): void
    {
        $this->classMap();
        $this->verifier('Post', <<<'PHP'
            return new class implements \Eleph\Runtime\Conformance\Verifier {
                public function verify(\Closure $classFor): array
                {
                    return [
                        new \Eleph\Runtime\Conformance\Signal(\Eleph\Runtime\Conformance\Severity::Info, 'checked ' . $classFor('Post')),
                        new \Eleph\Runtime\Conformance\Signal(\Eleph\Runtime\Conformance\Severity::Error, 'Post.title does not resolve'),
                    ];
                }
            };
            PHP);

        $signals = TreeCheck::run($this->tree);

        self::assertSame(['checked App\Post', 'Post.title does not resolve'], array_map(
            static fn ($signal): string => $signal->message,
            $signals,
        ));
        self::assertSame(Severity::Error, $signals[1]->severity);
    }

    public function testAFileThatReturnsNoVerifierIsAnError(): void
    {
        $this->classMap();
        $this->verifier('Post', 'return 3;');

        $signals = TreeCheck::run($this->tree);

        self::assertSame(Severity::Error, $signals[0]->severity);
        self::assertStringContainsString('did not return a verifier', $signals[0]->message);
    }

    public function testAVerifierThatThrowsOnLoadIsAnError(): void
    {
        $this->classMap();
        $this->verifier('Post', "throw new \\RuntimeException('boom');");

        $signals = TreeCheck::run($this->tree);

        self::assertSame(Severity::Error, $signals[0]->severity);
        self::assertStringContainsString('could not be loaded: boom', $signals[0]->message);
    }

    public function testTheBinaryPrintsOneJsonLinePerSignalAndFailsOnAnError(): void
    {
        $this->classMap();
        $this->verifier('Post', <<<'PHP'
            return new class implements \Eleph\Runtime\Conformance\Verifier {
                public function verify(\Closure $classFor): array
                {
                    return [new \Eleph\Runtime\Conformance\Signal(\Eleph\Runtime\Conformance\Severity::Warning, 'careful')];
                }
            };
            PHP);

        [$code, $stdout] = $this->binary(['--project', $this->tree, '--output', $this->tree]);

        self::assertSame(0, $code);
        self::assertSame(['{"severity":"warning","message":"careful"}'], explode("\n", trim($stdout)));

        unlink($this->tree . '/class-map.php');

        [$code, $stdout] = $this->binary(['--project', $this->tree, '--output', $this->tree]);

        self::assertSame(1, $code);
        self::assertStringContainsString('"severity":"error"', $stdout);
    }

    public function testTheBinaryRefusesToRunWithoutItsArguments(): void
    {
        [$code, $stdout] = $this->binary([]);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
    }

    private function classMap(): void
    {
        file_put_contents(
            $this->tree . '/class-map.php',
            "<?php return ['entities' => ['Post' => 'App\\\\Post'], 'classes' => []];",
        );
    }

    private function verifier(string $directory, string $body): void
    {
        file_put_contents($this->tree . '/' . $directory . '/verify.php', "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function binary(array $arguments): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/eleph-check-php', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout];
    }
}
