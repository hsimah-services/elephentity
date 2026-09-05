<?php

declare(strict_types=1);

namespace PheFr\Schema\Pattern;

use PheFr\Schema\Error\SpecError;

/**
 * Expands an entity's `use:` list into the full, ordered set of patterns that apply.
 *
 * Patterns may use other patterns, so expansion is recursive. A pattern reached twice
 * by different routes applies once rather than colliding with itself, and a cycle is
 * reported rather than hung on.
 */
final readonly class PatternResolver
{
    /**
     * @param array<string, PatternDefinition> $patterns
     * @param list<string>                     $uses
     *
     * @return array{patterns: list<PatternDefinition>, errors: list<SpecError>}
     */
    public function expand(array $patterns, array $uses, string $entityFile): array
    {
        $resolved = [];
        $errors = [];

        foreach ($uses as $name) {
            $this->visit($name, $patterns, $entityFile, $resolved, $errors, []);
        }

        return ['patterns' => array_values($resolved), 'errors' => $errors];
    }

    /**
     * @param array<string, PatternDefinition> $patterns
     * @param array<string, PatternDefinition> $resolved
     * @param list<SpecError>                  $errors
     * @param list<string>                     $trail
     */
    private function visit(
        string $name,
        array $patterns,
        string $entityFile,
        array &$resolved,
        array &$errors,
        array $trail,
    ): void {
        if (in_array($name, $trail, true)) {
            $errors[] = new SpecError(
                'pattern.cycle',
                sprintf('Pattern cycle: %s.', implode(' → ', [...$trail, $name])),
                $entityFile,
            );

            return;
        }

        if (isset($resolved[$name])) {
            return;
        }

        $pattern = $patterns[$name] ?? null;

        if (null === $pattern) {
            $errors[] = new SpecError(
                'pattern.unknown',
                sprintf('Unknown pattern "%s". Expected patterns/%s.yml.', $name, $name),
                $entityFile,
            );

            return;
        }

        // Depth first, so a pattern's own dependencies apply before it does.
        foreach ($pattern->uses as $dependency) {
            $this->visit($dependency, $patterns, $entityFile, $resolved, $errors, [...$trail, $name]);
        }

        $resolved[$name] = $pattern;
    }
}
