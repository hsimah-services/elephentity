<?php

declare(strict_types=1);

namespace Eleph\Cli;

use RuntimeException;

/**
 * The generated tree's own index, as the PHP builder wrote it.
 *
 * `eleph check` has to resolve a spec name to a class and load it, and it cannot ask
 * the generator: the generator is a separate program — one day in another language —
 * and by the time this runs it has exited. So the tree carries the answer, and the CLI
 * reads a published artefact rather than importing the generator's naming code.
 *
 * Nothing here trusts the file's shape. It is generated and signed, but it is still a
 * file on disk that can be deleted or truncated, and a gate that fatals on a bad map is
 * worse than one that says the map is bad.
 */
final readonly class ClassMap
{
    public const PATH = 'class-map.php';

    /**
     * @param array<string, string> $entities Spec name => fully-qualified class.
     * @param array<string, string> $classes  Fully-qualified class => file, relative to the tree.
     */
    private function __construct(
        private string $directory,
        private array $entities,
        private array $classes,
    ) {
    }

    public static function load(string $directory): self
    {
        $root = rtrim($directory, '/');
        $path = $root . '/' . self::PATH;

        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No %s in %s. Run `eleph generate` before checking conformance.',
                self::PATH,
                $root,
            ));
        }

        /** @var mixed $map */
        $map = require $path;

        if (!is_array($map)) {
            throw new RuntimeException(sprintf('%s did not return a map.', $path));
        }

        return new self(
            $root,
            self::stringMap($map['entities'] ?? null, $path, 'entities'),
            self::stringMap($map['classes'] ?? null, $path, 'classes'),
        );
    }

    /**
     * Make the generated tree loadable without the project's autoloader.
     *
     * Relying on the project's Composer configuration would make the gate silently pass
     * whenever that configuration was wrong, which is precisely when it should fail. The
     * map is exhaustive, so unlike the autoloader this replaces it needs no assumption
     * about how the tree is laid out.
     */
    public function autoload(): void
    {
        spl_autoload_register(function (string $class): void {
            $file = $this->classes[$class] ?? null;

            if (null === $file) {
                return;
            }

            $path = $this->directory . '/' . $file;

            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    /**
     * The class representing an entity, or the name itself when the tree has never
     * heard of it.
     *
     * Returning the name is what makes the failure legible: `ConformanceChecker` then
     * reports that the type is exposed but the class does not exist, which is the true
     * statement about an entity nobody generated.
     */
    public function classFor(string $entity): string
    {
        return $this->entities[$entity] ?? $entity;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value, string $path, string $key): array
    {
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('%s carries no "%s" map.', $path, $key));
        }

        $strings = [];

        /** @var mixed $entry */
        foreach ($value as $name => $entry) {
            if (!is_string($name) || !is_string($entry)) {
                throw new RuntimeException(sprintf(
                    '%s has an entry in "%s" that is not a string.',
                    $path,
                    $key,
                ));
            }

            $strings[$name] = $entry;
        }

        return $strings;
    }
}
