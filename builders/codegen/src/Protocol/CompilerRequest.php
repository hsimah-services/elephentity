<?php

declare(strict_types=1);

namespace Eleph\Codegen\Protocol;

use Eleph\Codegen\GeneratedFile;

/**
 * What the compiler sends: a compiled spec, and anything it wants written alongside.
 *
 * The mirror of `Envelope` one hop upstream. Elephentity parses the specs, resolves
 * patterns and produces the IR; this program has none of that and wants none of it. It
 * receives the result on stdin and never learns how it was arrived at, which is what
 * lets the compiler stay PHP while this becomes something else.
 *
 * The same versioning discipline applies, for the same reason: the versions sit outside
 * the payload so that this side can refuse an IR it does not understand before reading
 * a single entity of it.
 *
 * **`files` is how the compiler contributes output it alone can produce.** The
 * WordPress storage manifest and the GraphQL manifest are compiled from the IR by code
 * that knows what WordPress is, which is exactly the knowledge this program is built
 * not to have. They still have to be signed and swept with the tree they belong to, so
 * the compiler hands them over addressed to a target and they are written with that
 * target's files. A contributed file is signed identically to a generated one — the
 * signature says "machine-owned", not "produced by a builder".
 */
final readonly class CompilerRequest
{
    /**
     * @param array<string, mixed>               $schema The IR, forwarded and never read.
     * @param array<string, list<GeneratedFile>> $files  Target name => files the compiler produced.
     */
    private function __construct(
        public array $schema,
        public array $files,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): self
    {
        Envelope::assertVersions($data);

        $schema = $data['schema'] ?? null;

        if (!is_array($schema)) {
            throw new ProtocolException('The request carries no schema.');
        }

        /** @var array<string, mixed> $schema */
        return new self($schema, self::filesIn($data['files'] ?? []));
    }

    /**
     * The compiler's side of this exchange, kept here so both ends read from one file.
     *
     * @param array<string, mixed>               $schema        The IR, already encoded.
     * @param array<string, list<GeneratedFile>> $contributions Target name => files to write with it.
     *
     * @return array<string, mixed>
     */
    public static function encode(array $schema, array $contributions): array
    {
        $files = [];

        foreach ($contributions as $target => $contributed) {
            $files[$target] = array_map(
                static fn (GeneratedFile $file) => ['path' => $file->relativePath, 'body' => $file->body],
                $contributed,
            );
        }

        return [
            'elephentity' => Envelope::VERSION,
            'irVersion' => Envelope::IR_VERSION,
            'schema' => $schema,
            'files' => (object) $files,
        ];
    }

    /**
     * The files this run should write for one target on top of what its builder returns.
     *
     * @return list<GeneratedFile>
     */
    public function contributedTo(string $target): array
    {
        return $this->files[$target] ?? [];
    }

    /**
     * @return array<string, list<GeneratedFile>>
     */
    private static function filesIn(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ProtocolException('"files" must be an object keyed by target.');
        }

        $byTarget = [];

        /** @var mixed $files */
        foreach ($value as $target => $files) {
            if (!is_string($target) || '' === $target) {
                throw new ProtocolException('Every entry in "files" must be keyed by target name.');
            }

            if (!is_array($files)) {
                throw new ProtocolException(sprintf('"files" for target "%s" must be a list.', $target));
            }

            $byTarget[$target] = self::listOf($files, $target);
        }

        return $byTarget;
    }

    /**
     * @param array<mixed> $files
     *
     * @return list<GeneratedFile>
     */
    private static function listOf(array $files, string $target): array
    {
        $generated = [];

        /** @var mixed $file */
        foreach ($files as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;
            $body = is_array($file) ? ($file['body'] ?? null) : null;

            if (!is_string($path) || '' === $path || !is_string($body)) {
                throw new ProtocolException(sprintf(
                    'Every file contributed to "%s" must be an object with "path" and "body".',
                    $target,
                ));
            }

            if (str_contains($path, '..')) {
                // The same rule a builder is held to. The compiler is trusted more, but
                // not so much that a bug in it should be able to write outside the tree.
                throw new ProtocolException(sprintf('File path "%s" escapes the output directory.', $path));
            }

            $generated[] = new GeneratedFile($path, $body);
        }

        return $generated;
    }
}
