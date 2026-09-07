<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\Codegen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Elephentity's whole coupling to the code generator: a path to an executable, JSON on
 * its stdin, and its output on the way back.
 *
 * The generator is resolved and never fetched, so the two ways this breaks are a
 * generator that was never installed and one that is installed but not executable. Both
 * have to say so in a way that names the fix.
 */
#[CoversClass(Codegen::class)]
final class CodegenTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/eleph-codegen-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            unlink($path);
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testTheCompiledSpecReachesTheGeneratorOnStdin(): void
    {
        $this->stub('echo file_get_contents("php://stdin");');

        $output = new BufferedOutput();
        $exit = $this->codegen()->run(['generate'], '{"schema":{}}', $output);

        self::assertSame(0, $exit);
        self::assertSame('{"schema":{}}', $output->fetch());
    }

    public function testTheArgumentsReachItToo(): void
    {
        $this->stub('echo implode(" ", array_slice($argv, 1));');

        $output = new BufferedOutput();
        $this->codegen()->run(['generate', '--check'], '{}', $output);

        self::assertSame('generate --check', $output->fetch());
    }

    public function testTheGeneratorsExitCodeIsTheGate(): void
    {
        // The generator decides whether the build passed. Anything this did with that
        // other than return it would be a second opinion.
        $this->stub('exit(1);');

        self::assertSame(1, $this->codegen()->run(['generate'], '{}', new BufferedOutput()));
    }

    public function testItsReportIsForwardedRatherThanRestated(): void
    {
        // Written raw: the generator already decided how its report reads, and Symfony
        // would otherwise read anything angle-bracketed in it as a style tag.
        $this->stub('echo "<info>php</info>: 3 file(s)\n";');

        $output = new BufferedOutput();
        $this->codegen()->run(['generate'], '{}', $output);

        self::assertSame("<info>php</info>: 3 file(s)\n", $output->fetch());
    }

    public function testWhatItWroteToStderrIsNotLost(): void
    {
        $this->stub('fwrite(STDERR, "builder crashed\n"); exit(1);');

        $output = new BufferedOutput();
        $this->codegen()->run(['generate'], '{}', $output);

        self::assertStringContainsString('builder crashed', $output->fetch());
    }

    public function testAQuestionCanBeAskedAndAnswered(): void
    {
        $this->stub('echo \'{"targets":{"php":{"output":"generated"}}}\';');

        self::assertSame(
            '{"targets":{"php":{"output":"generated"}}}',
            $this->codegen()->capture(['targets']),
        );
    }

    public function testAGeneratorThatIsNotExecutableSaysHowToFixIt(): void
    {
        // The single most likely thing to go wrong after checking one out by hand.
        $path = $this->root . '/eleph-codegen';
        file_put_contents($path, "#!/usr/bin/env php\n<?php\n");
        chmod($path, 0o644);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not executable\. Try `chmod \+x/');

        $this->codegen()->run(['generate'], '{}', new BufferedOutput());
    }

    public function testAMissingGeneratorSaysWhereItLookedAndWhatToInstall(): void
    {
        try {
            (new Codegen($this->root, 'tools/nowhere'))->run(['generate'], '{}', new BufferedOutput());
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tools/nowhere', $exception->getMessage());
            self::assertStringContainsString('composer require --dev elephentity/codegen', $exception->getMessage());

            // The division of labour is the thing a confused reader needs told.
            self::assertStringContainsString('does not generate code itself', $exception->getMessage());

            return;
        }

        self::fail('A missing generator should not resolve.');
    }

    private function codegen(): Codegen
    {
        return new Codegen($this->root, 'eleph-codegen');
    }

    private function stub(string $code): void
    {
        $path = $this->root . '/eleph-codegen';

        file_put_contents($path, "#!/usr/bin/env php\n<?php\n" . $code . "\n");
        chmod($path, 0o775);
    }
}
