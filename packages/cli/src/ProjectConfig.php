<?php

declare(strict_types=1);

namespace PheFr\Cli;

use JsonException;
use RuntimeException;

/**
 * phefr.json — the paths and namespaces a project generates into.
 *
 * Configuration, deliberately separate from specification. Namespaces live here so
 * that renaming one is a config change rather than an edit to every entity yaml.
 */
final readonly class ProjectConfig
{
    public const FILENAME = 'phefr.json';

    public function __construct(
        public string $specDirectory,
        public string $outputDirectory,
        public string $rootNamespace,
        public string $typeNamespace,
    ) {
    }

    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/') . '/' . self::FILENAME;

        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No %s in %s. It needs "spec", "output", "namespace" and "typeNamespace".',
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

        return new self(
            self::string($data, 'spec', $path),
            self::string($data, 'output', $path),
            self::string($data, 'namespace', $path),
            self::string($data, 'typeNamespace', $path),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || '' === $value) {
            throw new RuntimeException(sprintf('%s must set "%s" to a non-empty string.', $path, $key));
        }

        return $value;
    }
}
