<?php

declare(strict_types=1);

namespace Eleph\Cli;

use RuntimeException;

/**
 * Finds the program that generates a target, and refuses to guess.
 *
 * **Resolution, never acquisition.** Elephentity does not fetch, pin, checksum or cache
 * builders: composer, npm and cargo already do that, and reimplementing them badly is
 * not the product. A builder has to already be present — checked out, symlinked,
 * downloaded by a script, copied in by hand; the framework's only interest is that the
 * executable is there. See docs/PLAN.md §15.
 *
 * Three places, in order: an explicit path, the project's builders directory, then
 * PATH. When none of them has it the error names all three, because "builder not found"
 * without saying where it looked is the least useful thing a build can say.
 */
final readonly class Builders
{
    public const DEFAULT_DIRECTORY = 'tools/builders';

    public function __construct(
        private string $projectRoot,
        private string $directory = self::DEFAULT_DIRECTORY,
    ) {
    }

    /**
     * @return list<string> The executable and its arguments.
     *
     * @throws RuntimeException When the builder is not installed.
     */
    public function resolve(TargetConfig $target): array
    {
        $builder = $target->builder;

        if (null === $builder) {
            throw new RuntimeException(sprintf('Target "%s" declares no builder.', $target->name));
        }

        $looked = [];

        // An explicit path is taken at its word: someone who wrote a path meant that
        // file, and silently falling back to a different one on PATH would be worse
        // than failing.
        if (str_contains($builder, '/')) {
            $path = str_starts_with($builder, '/') ? $builder : $this->projectRoot . '/' . $builder;
            $looked[] = $path;

            if (is_file($path)) {
                return $this->executable($path, $target->name);
            }

            throw $this->missing($target->name, $builder, $looked);
        }

        $inProject = rtrim($this->projectRoot, '/') . '/' . trim($this->directory, '/') . '/' . $builder;
        $looked[] = $inProject;

        if (is_file($inProject)) {
            return $this->executable($inProject, $target->name);
        }

        $onPath = $this->onPath($builder, $looked);

        if (null !== $onPath) {
            return $this->executable($onPath, $target->name);
        }

        throw $this->missing($target->name, $builder, $looked);
    }

    /**
     * @param list<string> $looked
     */
    private function onPath(string $builder, array &$looked): ?string
    {
        $path = getenv('PATH');

        if (!is_string($path) || '' === $path) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ('' === $directory) {
                continue;
            }

            $candidate = rtrim($directory, '/') . '/' . $builder;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $looked[] = 'anywhere on PATH';

        return null;
    }

    /**
     * @return list<string>
     */
    private function executable(string $path, string $target): array
    {
        if (!is_executable($path)) {
            throw new RuntimeException(sprintf(
                'The builder for target "%s" is at %s but is not executable. Try `chmod +x %s`.',
                $target,
                $path,
                $path,
            ));
        }

        return [$path];
    }

    /**
     * @param list<string> $looked
     */
    private function missing(string $target, string $builder, array $looked): RuntimeException
    {
        return new RuntimeException(sprintf(
            "No builder \"%s\" for target \"%s\". Looked in:\n  %s\n"
            . 'Install it and put it where one of those points; Elephentity does not fetch builders.',
            $builder,
            $target,
            implode("\n  ", $looked),
        ));
    }
}
