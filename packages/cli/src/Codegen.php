<?php

declare(strict_types=1);

namespace Eleph\Cli;

use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finds and runs the code generator.
 *
 * Elephentity compiles specs. Turning the result into files is a separate program —
 * `eleph-codegen`, which resolves a project's builders, runs them, and signs and writes
 * what they return. This is the whole of the coupling between them: a path to an
 * executable and JSON on its stdin.
 *
 * **Resolution, never acquisition**, exactly as the generator treats its own builders.
 * Four places, in order: an explicit `"codegen"` path in eleph.json; alongside the
 * `eleph` binary that is running, which is where Composer puts both; the nearest
 * `vendor/bin` above this file, which is where it is when `eleph` is run from its own
 * source rather than from `vendor/bin`; then `PATH`. When none of them has it the error
 * names all four, because "not found" without saying where it looked is the least
 * useful thing a build can say.
 *
 * Output is forwarded rather than re-rendered. The generator owns how a run is reported
 * — which files changed, which had been hand-edited, which target is out of date — and a
 * wrapper that restated any of that would be a second thing to keep in step with a
 * report it does not produce.
 *
 * *How* it is forwarded depends on where it is going. Attached to a terminal the child
 * inherits this process's streams directly, so it decides about colour and its progress
 * appears as it happens. Anywhere else — a pipe, CI, a test — it is captured and written
 * back out through the caller's output, because a command whose child wrote straight to
 * file descriptor 1 could not be redirected, silenced or asserted on, and would leak
 * through any harness that thought it had captured it.
 */
final readonly class Codegen
{
    public const COMMAND = 'eleph-codegen';

    public function __construct(
        private string $projectRoot,
        private ?string $override = null,
    ) {
    }

    /**
     * Run a subcommand with `$input` on stdin, forwarding its output.
     *
     * @param list<string> $arguments
     *
     * @return int The generator's exit code, which is the gate.
     */
    public function run(array $arguments, string $input, OutputInterface $output): int
    {
        $command = [$this->resolve(), ...$arguments];

        // stdin is a file rather than a pipe: an IR of any size can exceed the pipe
        // buffer, and a child that has not finished reading stdin before it starts
        // writing stdout would deadlock — only on large schemas, which is the worst
        // possible time to discover it.
        $stdin = $this->stage($input);
        $inherit = $this->attachedToATerminal();

        // stderr is a file for the same reason stdin is, when it is not inherited:
        // draining one pipe to completion while the other fills is the deadlock again,
        // and a file cannot fill.
        $stderr = $inherit ? null : $this->stage('');

        $descriptors = $inherit
            ? [0 => ['file', $stdin, 'r'], 1 => STDOUT, 2 => STDERR]
            : [0 => ['file', $stdin, 'r'], 1 => ['pipe', 'w'], 2 => ['file', (string) $stderr, 'w']];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->discard($stdin, $stderr);

            throw new RuntimeException(sprintf('Could not run %s.', implode(' ', $command)));
        }

        try {
            if ($inherit) {
                return proc_close($process);
            }

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $exit = proc_close($process);

            // Raw, because the generator already decided how its report reads and
            // Symfony would otherwise interpret anything in it that looks like a tag.
            $output->write(is_string($stdout) ? $stdout : '', false, OutputInterface::OUTPUT_RAW);

            $this->writeErrors($output, (string) file_get_contents((string) $stderr));

            return $exit;
        } finally {
            $this->discard($stdin, $stderr);
        }
    }

    private function writeErrors(OutputInterface $output, string $stderr): void
    {
        if ('' === $stderr) {
            return;
        }

        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $errors->write($stderr, false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Whether the generator can be handed this process's own streams.
     *
     * Only when both are terminals: a run with stdout redirected and stderr not is one
     * where inheriting either would put the two halves of the report in different
     * places.
     */
    private function attachedToATerminal(): bool
    {
        return \defined('STDOUT') && \defined('STDERR')
            && stream_isatty(STDOUT) && stream_isatty(STDERR);
    }

    private function discard(string $stdin, ?string $stderr): void
    {
        @unlink($stdin);

        if (null !== $stderr) {
            @unlink($stderr);
        }
    }

    /**
     * Run a subcommand that answers a question, and return what it said.
     *
     * @param list<string> $arguments
     */
    public function capture(array $arguments): string
    {
        $command = [$this->resolve(), ...$arguments];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Could not run %s.', implode(' ', $command)));
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        if (0 !== proc_close($process)) {
            throw new RuntimeException(sprintf('%s failed.', implode(' ', $command)));
        }

        return is_string($stdout) ? $stdout : '';
    }

    private function resolve(): string
    {
        $looked = [];

        if (null !== $this->override) {
            $path = str_starts_with($this->override, '/')
                ? $this->override
                : $this->projectRoot . '/' . $this->override;
            $looked[] = $path;

            // Someone who wrote a path meant that file. Falling back to a different one
            // on PATH would be worse than failing.
            if (is_file($path)) {
                return $this->executable($path);
            }

            throw $this->missing($looked);
        }

        foreach ([$this->besideTheCli(), $this->inNearestVendorBin()] as $candidate) {
            if (null === $candidate) {
                continue;
            }

            $looked[] = $candidate;

            if (is_file($candidate)) {
                return $this->executable($candidate);
            }
        }

        $onPath = $this->onPath();

        if (null !== $onPath) {
            return $this->executable($onPath);
        }

        $looked[] = 'anywhere on PATH';

        throw $this->missing($looked);
    }

    /**
     * Composer installs `eleph` and `eleph-codegen` into the same bin directory, so the
     * one running is the best evidence of where the other is.
     */
    private function besideTheCli(): ?string
    {
        /** @var mixed $argv */
        $argv = $_SERVER['argv'] ?? null;
        $self = is_array($argv) ? ($argv[0] ?? null) : null;

        if (!is_string($self) || '' === $self) {
            return null;
        }

        $resolved = realpath($self);

        return false === $resolved ? null : dirname($resolved) . '/' . self::COMMAND;
    }

    /**
     * The nearest `vendor/bin` above this file.
     *
     * Composer does not install a root package's own binaries, so a checkout of
     * Elephentity running `packages/cli/bin/eleph` has no sibling to find — but its
     * `vendor/bin` is three directories up. Walking rather than counting, because
     * installed as a dependency the depth is different and a hardcoded `../../..` would
     * be right in exactly one of the two layouts.
     */
    private function inNearestVendorBin(): ?string
    {
        $directory = __DIR__;

        while ('/' !== $directory && '' !== $directory) {
            $candidate = $directory . '/vendor/bin/' . self::COMMAND;

            if (is_file($candidate)) {
                return $candidate;
            }

            $directory = dirname($directory);
        }

        return null;
    }

    private function onPath(): ?string
    {
        $path = getenv('PATH');

        if (!is_string($path) || '' === $path) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ('' === $directory) {
                continue;
            }

            $candidate = rtrim($directory, '/') . '/' . self::COMMAND;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function executable(string $path): string
    {
        if (!is_executable($path)) {
            throw new RuntimeException(sprintf(
                'The code generator is at %s but is not executable. Try `chmod +x %s`.',
                $path,
                $path,
            ));
        }

        return $path;
    }

    /**
     * @param list<string> $looked
     */
    private function missing(array $looked): RuntimeException
    {
        return new RuntimeException(sprintf(
            "No %s found. Looked in:\n  %s\n"
            . 'Install it with `composer require --dev elephentity/codegen`, or set a '
            . '"codegen" path in %s. Elephentity compiles specs; it does not generate code itself.',
            self::COMMAND,
            implode("\n  ", $looked),
            ProjectConfig::FILENAME,
        ));
    }

    private function stage(string $input): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eleph-ir-');

        if (false === $path) {
            throw new RuntimeException('Could not stage the compiled spec for the generator.');
        }

        file_put_contents($path, $input);

        return $path;
    }
}
