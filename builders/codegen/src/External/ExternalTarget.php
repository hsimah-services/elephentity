<?php

declare(strict_types=1);

namespace Eleph\Codegen\External;

use Eleph\Codegen\Protocol\Description;
use Eleph\Codegen\Protocol\Envelope;
use Eleph\Codegen\Protocol\ProtocolException;
use Eleph\Codegen\TargetRequest;
use Eleph\Codegen\TargetResponse;
use JsonException;
use RuntimeException;

/**
 * A target that is somebody else's program.
 *
 * The request goes in on stdin as JSON and the files come back on stdout, which is how
 * `protoc` has hosted generators in other languages for twenty years. Everything the
 * core cares about — signing, writing, drift detection — happens on this side of the
 * pipe, so a builder is only ever trusted to produce bytes.
 *
 * The IR passes through encoded and is never decoded here. What an entity means is the
 * builder's problem; this class only has to get the bytes to it and the files back.
 *
 * **stdin and stderr are temporary files rather than pipes.** With three pipes, a
 * builder that writes more to stdout than the pipe buffer holds before it has finished
 * reading stdin deadlocks, and it does so only on large schemas, which is the worst
 * possible time to discover it. Files cannot deadlock, and the builder cannot tell the
 * difference: stdin is still stdin.
 */
final readonly class ExternalTarget
{
    /**
     * @param list<string> $command Executable and its arguments, unescaped.
     */
    public function __construct(
        private string $name,
        private array $command,
        private ?string $workingDirectory = null,
    ) {
    }

    /**
     * @param array<string, mixed> $schema The IR, encoded, and never read here.
     */
    public function generate(TargetRequest $request, array $schema): TargetResponse
    {
        try {
            $payload = Envelope::toJson(Envelope::encodeRequest($this->name, $request, $schema));
        } catch (JsonException $exception) {
            return TargetResponse::failed([
                sprintf('Could not encode the request for %s: %s', $this->name, $exception->getMessage()),
            ]);
        }

        try {
            $stdout = $this->exchange($payload);
        } catch (RuntimeException $exception) {
            return TargetResponse::failed([$exception->getMessage()]);
        }

        try {
            return Envelope::decodeResponse(Envelope::fromJson($stdout));
        } catch (ProtocolException $exception) {
            return TargetResponse::failed([sprintf(
                'The %s builder sent something unusable: %s',
                $this->name,
                $exception->getMessage(),
            )]);
        }
    }

    /**
     * Ask what this builder provides, without generating anything.
     *
     * Runs before a spec has been compiled, because what it answers is what the
     * compiler needs in order to compile: which integrations a spec may name, which
     * drivers are installed. That ordering is the whole reason describe exists as a
     * separate exchange rather than a field on the generate response.
     */
    public function describe(): Description
    {
        try {
            $payload = Envelope::toJson(Envelope::encodeDescribeRequest($this->name));
        } catch (JsonException $exception) {
            return Description::failed($this->name, [sprintf(
                'Could not encode the describe request for %s: %s',
                $this->name,
                $exception->getMessage(),
            )]);
        }

        try {
            return Description::of(
                $this->name,
                Envelope::decodeDescription(Envelope::fromJson($this->exchange($payload))),
            );
        } catch (ProtocolException|RuntimeException $exception) {
            // One arm for both failures on purpose. Whether the builder crashed or
            // answered something unusable, the likeliest cause is the same — it was
            // written before describe existed and read the request as a generate — and
            // a caller who has to work that out from which exception surfaced is being
            // told about our layering rather than about their problem.
            return Description::failed($this->name, [sprintf(
                'The %s builder could not describe itself: %s'
                . "\n  A builder must answer a \"describe\" request; one written before "
                . 'describe existed reads it as a generate and fails on the missing schema.',
                $this->name,
                trim($exception->getMessage()),
            )]);
        }
    }

    /**
     * One request in, one response out, or an exception naming what went wrong.
     *
     * Shared by both verbs deliberately: they are the same exchange with a different
     * discriminator, and a second copy of the process handling would be a second place
     * for the deadlock avoidance below to be got wrong.
     *
     * @throws RuntimeException
     */
    private function exchange(string $payload): string
    {
        $result = $this->run($payload);

        if (0 !== $result->exitCode) {
            throw new RuntimeException(sprintf(
                '%s exited %d.%s',
                implode(' ', $this->command),
                $result->exitCode,
                '' === trim($result->stderr) ? '' : "\n  " . trim($result->stderr),
            ));
        }

        return $result->stdout;
    }

    private function run(string $payload): ProcessResult
    {
        $input = $this->temporaryFile($payload);
        $errors = $this->temporaryFile('');

        $descriptors = [
            0 => ['file', $input, 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $errors, 'w'],
        ];

        $pipes = [];
        $process = @proc_open($this->command, $descriptors, $pipes, $this->workingDirectory);

        if (!is_resource($process)) {
            @unlink($input);
            @unlink($errors);

            throw new RuntimeException(sprintf('Could not run %s.', implode(' ', $this->command)));
        }

        try {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $exitCode = proc_close($process);

            return new ProcessResult(
                is_string($stdout) ? $stdout : '',
                (string) file_get_contents($errors),
                $exitCode,
            );
        } finally {
            @unlink($input);
            @unlink($errors);
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eleph-');

        if (false === $path) {
            throw new RuntimeException('Could not create a temporary file for the builder exchange.');
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
