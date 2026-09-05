<?php

declare(strict_types=1);

namespace PheFr\Schema\Spec;

use PheFr\Schema\Error\SpecError;
use PheFr\Schema\SpecSource;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads spec documents off disk and parses them. Shape is somebody else's problem.
 */
final readonly class SpecLoader
{
    /**
     * @return array{specs: list<RawSpec>, errors: list<SpecError>}
     */
    public function load(SpecSource $source): array
    {
        $specs = [];
        $errors = [];

        foreach (SpecKind::cases() as $kind) {
            $directory = $source->directoryFor($kind);

            if (!is_dir($directory)) {
                continue;
            }

            foreach ($this->yamlFilesIn($directory) as $file) {
                $parsed = $this->parse($file, $kind);

                if ($parsed instanceof SpecError) {
                    $errors[] = $parsed;

                    continue;
                }

                $specs[] = $parsed;
            }
        }

        return ['specs' => $specs, 'errors' => $errors];
    }

    /**
     * @return list<string>
     */
    private function yamlFilesIn(string $directory): array
    {
        $files = [];

        foreach (['yml', 'yaml'] as $extension) {
            $matches = glob($directory . '/*.' . $extension);

            if (false === $matches) {
                continue;
            }

            foreach ($matches as $match) {
                $files[] = $match;
            }
        }

        sort($files);

        return $files;
    }

    private function parse(string $file, SpecKind $kind): RawSpec|SpecError
    {
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            return new SpecError(
                'spec.unparseable',
                sprintf('Could not parse YAML: %s', $exception->getMessage()),
                $file,
            );
        }

        if (!is_array($parsed)) {
            return new SpecError(
                'spec.notAMap',
                'A spec document must be a map at its root.',
                $file,
            );
        }

        /** @var array<string, mixed> $parsed */
        if (!array_key_exists($kind->value, $parsed)) {
            return new SpecError(
                'spec.wrongKind',
                sprintf(
                    'A file in %s/ must declare "%s:" at its root.',
                    $kind->directory(),
                    $kind->value,
                ),
                $file,
            );
        }

        return new RawSpec($kind, $file, $parsed);
    }
}
