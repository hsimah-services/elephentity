<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The acceptance suite: a committed request in, a committed response out, byte for byte.
 *
 * These snapshots pin the native Rust printer's output. The original PHP snapshots
 * remain in response.reference.json; tools/compare-rust.php compares their resolved
 * PHP syntax trees so formatting changes do not require a compatibility printer.
 *
 * It runs the real binary rather than `PhpTarget`, so what is pinned includes the
 * entrypoint: the version gate, the JSON encoding, the exit code and the promise that
 * nothing but the response reaches stdout.
 *
 * When a deliberate change to the generator moves these, regenerate them and read the
 * diff — it is the clearest description available of what the change did to every
 * project's tree.
 */
#[CoversNothing]
final class GoldenTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function cases(): array
    {
        return ['the canonical spec' => ['valid'], 'the worked example' => ['clog'], 'a pattern-generated interface and trait' => ['patterns'], 'a required to-one edge' => ['edge-required'], 'runtime creation and verification contracts' => ['runtime-contracts']];
    }

    #[DataProvider('cases')]
    public function testTheBuilderReproducesItsFrozenResponse(string $case): void
    {
        $directory = __DIR__ . '/fixtures/golden/' . $case;

        $result = $this->invoke((string) file_get_contents($directory . '/request.json'));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stderr'], 'Nothing but the response may be written.');

        /** @var array{files: list<array{path: string, body: string}>, errors: list<mixed>} $response */
        $response = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($response['files'] as $file) {
            self::assertDoesNotMatchRegularExpression('/[ \t]+$/m', $file['body'], $file['path']);
        }

        // Compared as decoded structures so the diff PHPUnit prints names the file that
        // moved, rather than reporting one 58KB string differing from another.
        self::assertSame(
            json_decode((string) file_get_contents($directory . '/response.json'), true, 512, JSON_THROW_ON_ERROR),
            json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[DataProvider('cases')]
    public function testTheResponseIsOneJsonObjectAndNothingElse(string $case): void
    {
        // stdout is the response. A stray warning or a debug print would still decode
        // above, because json_decode stops at the end of the object.
        $result = $this->invoke(
            (string) file_get_contents(__DIR__ . '/fixtures/golden/' . $case . '/request.json'),
        );

        self::assertSame(
            $result['stdout'],
            (string) json_encode(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    public function testARequestFromAnotherIrVersionIsRefusedWithoutGenerating(): void
    {
        $request = $this->request('valid');
        $request['irVersion'] = '99.0';

        $result = $this->invoke((string) json_encode($request));

        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout'], 'A refusal writes nothing to stdout.');
        self::assertStringContainsString('IR version mismatch', $result['stderr']);
    }

    public function testAMisconfiguredTargetReportsEveryProblemAtOnce(): void
    {
        $request = $this->request('valid');
        $request['config'] = new stdClass();

        $result = $this->invoke((string) json_encode($request));

        self::assertSame(0, $result['exit'], 'Bad config is a response with errors, not a crash.');

        /** @var array{errors: list<string>, files: list<mixed>} $response */
        $response = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        // Both, not the first: running the build once per missing key is exactly what
        // the accumulate-and-report convention exists to prevent.
        self::assertCount(2, $response['errors']);
        self::assertSame([], $response['files']);
    }

    public function testItDescribesItselfAsProvidingOnlyAChecker(): void
    {
        // A language generator provides no integration, driver or pattern, so the one
        // thing it says is how its tree is checked. `eleph check` fills in the two
        // placeholders and finds eleph-check-php in the nearest vendor/bin.
        $result = $this->invoke((string) json_encode([
            'elephentity' => 1,
            'irVersion' => '1.2',
            'request' => 'describe',
            'target' => 'php',
        ]));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame(
            [
                'elephentity' => 1,
                'irVersion' => '1.2',
                'provides' => ['check' => ['command' => [
                    'eleph-check-php', '--project', '{project}', '--output', '{output}',
                ]]],
            ],
            json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testDescribeIsRefusedFromAnotherIrVersionToo(): void
    {
        // The gate is on the exchange, not on the payload. A describe carries no schema
        // and still must not be answered across a version boundary, because what it
        // answers shapes the spec the other side is about to compile.
        $result = $this->invoke((string) json_encode([
            'elephentity' => 1,
            'irVersion' => '99.0',
            'request' => 'describe',
            'target' => 'php',
        ]));

        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('IR version mismatch', $result['stderr']);
    }

    public function testAnUnknownRequestNamesWhatItCanAnswer(): void
    {
        $result = $this->invoke((string) json_encode([
            'elephentity' => 1,
            'irVersion' => '1.0',
            'request' => 'compile',
        ]));

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Unknown request "compile"', $result['stderr']);
    }

    public function testNothingOnStdinIsSaidPlainly(): void
    {
        $result = $this->invoke('');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('expected a request on stdin', $result['stderr']);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $case): array
    {
        /** @var array<string, mixed> $request */
        $request = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/golden/' . $case . '/request.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $request;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function invoke(string $input): array
    {
        $process = proc_open(
            [getenv('ELEPH_BUILDER_BINARY') ?: dirname(__DIR__) . '/bin/eleph-gen-php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not run eleph-gen-php.');
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
