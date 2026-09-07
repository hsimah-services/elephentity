<?php

declare(strict_types=1);

namespace Eleph\Cli;

use JsonException;
use RuntimeException;

/**
 * eleph.json, as far as Elephentity is concerned: where the specs are.
 *
 * The file has more in it than this — a `targets` block saying what a project generates
 * and which builder produces each one — and none of that is read here. Generating is a
 * separate program's job, and it is the only thing that parses those keys. Two parsers
 * would eventually disagree about something small, like whether a trailing slash on
 * `output` matters, and the disagreement would surface as a tree written to the wrong
 * place.
 *
 * So this reads `spec`, and `codegen` when a project needs to say where its generator
 * is. Anything it does not recognise it leaves alone, because the file is not only its.
 */
final readonly class ProjectConfig
{
    public const FILENAME = 'eleph.json';

    public function __construct(
        public string $specDirectory,
        public ?string $codegen = null,
    ) {
    }

    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/') . '/' . self::FILENAME;

        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No %s in %s. It needs "spec" and a "targets" block.',
                self::FILENAME,
                $directory,
            ));
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException(sprintf('Cannot read %s.', $path));
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('%s is not valid JSON: %s', $path, $exception->getMessage()));
        }

        $spec = $data['spec'] ?? null;

        if (!is_string($spec) || '' === $spec) {
            throw new RuntimeException(sprintf(
                "%s is not usable:\n  \"spec\" must be a non-empty string.",
                $path,
            ));
        }

        $codegen = $data['codegen'] ?? null;

        return new self($spec, is_string($codegen) && '' !== $codegen ? $codegen : null);
    }
}
