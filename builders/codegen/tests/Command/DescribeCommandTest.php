<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests\Command;

use Eleph\Codegen\Protocol\Envelope;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The handshake, driven the way Elephentity drives it.
 *
 * A subprocess test for the same reason the generate one is: the entrypoint is the
 * contract. What this pins is that `provides` survives the round trip untouched — this
 * program forwards it without knowing what an integration is, so a test that asserted
 * on its *meaning* would be testing a coupling that is supposed not to exist.
 */
#[CoversNothing]
final class DescribeCommandTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/eleph-describe-' . bin2hex(random_bytes(6));

        mkdir($this->project . '/tools/builders', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testWhatABuilderProvidesArrivesUntouched(): void
    {
        $this->writeBuilder('eleph-gen-fixture', provides: [
            'integrations' => ['wpgraphql' => ['entityConfig' => ['singular' => ['type' => 'string']]]],
            'drivers' => ['wordpress'],
        ]);
        $this->writeConfig(['php' => 'eleph-gen-fixture']);

        $result = $this->describe();

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(
            ['integrations' => ['wpgraphql' => ['entityConfig' => ['singular' => ['type' => 'string']]]], 'drivers' => ['wordpress']],
            $this->targets($result['stdout'])['php'],
        );
    }

    public function testEveryBuilderIsAskedAndTheAnswersAreKeyedByTarget(): void
    {
        $this->writeBuilder('eleph-gen-a', provides: ['drivers' => ['wordpress']]);
        $this->writeBuilder('eleph-gen-b', provides: ['integrations' => ['wpgraphql' => []]]);
        $this->writeConfig(['a' => 'eleph-gen-a', 'b' => 'eleph-gen-b']);

        $targets = $this->targets($this->describe()['stdout']);

        self::assertSame(['drivers' => ['wordpress']], $targets['a']);
        self::assertSame(['integrations' => ['wpgraphql' => []]], $targets['b']);
    }

    public function testABuilderProvidingNothingIsStillAnAnswer(): void
    {
        // A language generator provides no integration and no driver. That is not a
        // failure, and treating it as one would make every project with a plain PHP
        // target unbuildable.
        $this->writeBuilder('eleph-gen-fixture', provides: []);
        $this->writeConfig(['php' => 'eleph-gen-fixture']);

        $result = $this->describe();

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame([], $this->targets($result['stdout'])['php']);
    }

    public function testABuilderThatCannotDescribeItselfFailsTheRun(): void
    {
        // Carrying on with a partial answer would have the compiler report "unknown
        // integration" for one that is installed and merely unreachable.
        $this->writeBuilder('eleph-gen-old', provides: null);
        $this->writeConfig(['php' => 'eleph-gen-old']);

        $result = $this->describe();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('could not describe itself', $result['stderr']);
        self::assertSame('', trim($result['stdout']), 'stdout must stay parseable');
    }

    public function testEveryUnreachableBuilderIsNamedInOneRun(): void
    {
        $this->writeConfig(['a' => 'eleph-gen-nowhere', 'b' => 'eleph-gen-elsewhere']);

        $result = $this->describe();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('eleph-gen-nowhere', $result['stderr']);
        self::assertStringContainsString('eleph-gen-elsewhere', $result['stderr']);
    }

    /**
     * The `targets` block of a successful run, keyed by target.
     *
     * @return array<string, mixed>
     */
    private function targets(string $stdout): array
    {
        $decoded = json_decode($stdout, true);

        self::assertIsArray($decoded);
        self::assertSame(Envelope::VERSION, $decoded['elephentity'] ?? null);
        self::assertIsArray($decoded['targets'] ?? null);

        /** @var array<string, mixed> $targets */
        $targets = $decoded['targets'];

        return $targets;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function describe(): array
    {
        $command = [
            ...(getenv('ELEPH_CODEGEN_BINARY') ? [(string) getenv('ELEPH_CODEGEN_BINARY')] : [dirname(__DIR__, 2) . '/bin/eleph-codegen']),
            'describe',
            '--project',
            $this->project,
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not run eleph-codegen.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * A builder that answers describe, or — with null — one written before describe
     * existed, which reads the request as a generate and fails on the missing schema.
     *
     * @param array<string, mixed>|null $provides
     */
    private function writeBuilder(string $name, ?array $provides): void
    {
        $path = $this->project . '/tools/builders/' . $name;

        $body = null === $provides
            ? <<<'PHP'
                fwrite(STDERR, "expected a schema\n");
                exit(1);
                PHP
            : sprintf(
                <<<'PHP'
                    if (($request['request'] ?? 'generate') === 'describe') {
                        echo json_encode([
                            'elephentity' => %d,
                            'irVersion' => %s,
                            'provides' => %s,
                        ]);

                        exit(0);
                    }

                    fwrite(STDERR, "only describe is exercised here\n");
                    exit(1);
                    PHP,
                Envelope::VERSION,
                var_export(Envelope::IR_VERSION, true),
                [] === $provides ? '(object) []' : var_export($provides, true),
            );

        file_put_contents($path, "#!/usr/bin/env php\n<?php\n\n"
            . "\$request = json_decode(file_get_contents('php://stdin'), true);\n\n"
            . $body . "\n");

        chmod($path, 0o775);
    }

    /**
     * @param array<string, string> $builders Target name => builder.
     */
    private function writeConfig(array $builders): void
    {
        $targets = [];

        foreach ($builders as $name => $builder) {
            $targets[$name] = ['output' => 'generated/' . $name, 'builder' => $builder];
        }

        file_put_contents($this->project . '/eleph.json', (string) json_encode([
            'spec' => 'spec',
            'targets' => $targets,
        ], JSON_PRETTY_PRINT));
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? $this->remove($path) : unlink($path);
        }

        rmdir($directory);
    }
}
