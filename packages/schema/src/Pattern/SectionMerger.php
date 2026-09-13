<?php

declare(strict_types=1);

namespace Eleph\Schema\Pattern;

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Ir\Contributed;
use Eleph\Schema\Spec\ParsedSections;

/**
 * Merges pattern sections into an entity's own, sealed.
 *
 * A member declared twice is a hard error, whoever declared it. Overridable patterns
 * would mean reading one file no longer tells you what a field is, which is precisely
 * the drift the framework exists to prevent — so the resolution is to stop using the
 * pattern, not to shadow it.
 */
final readonly class SectionMerger
{
    /**
     * @param list<ParsedSections> $contributions Patterns first, the entity's own last.
     *
     * @return array{sections: ParsedSections, errors: list<SpecError>}
     */
    public function merge(array $contributions, string $entityName, string $entityFile): array
    {
        $errors = [];

        $fields = $this->mergeSection(
            array_map(static fn (ParsedSections $s): array => $s->fields, $contributions),
            'field',
            $entityName,
            $entityFile,
            $errors,
        );

        $edges = $this->mergeSection(
            array_map(static fn (ParsedSections $s): array => $s->edges, $contributions),
            'edge',
            $entityName,
            $entityFile,
            $errors,
        );

        $queries = $this->mergeSection(
            array_map(static fn (ParsedSections $s): array => $s->queries, $contributions),
            'query',
            $entityName,
            $entityFile,
            $errors,
        );

        $actions = $this->mergeSection(
            array_map(static fn (ParsedSections $s): array => $s->actions, $contributions),
            'action',
            $entityName,
            $entityFile,
            $errors,
        );

        $triggers = $this->mergeSection(
            array_map(static fn (ParsedSections $s): array => $s->triggers, $contributions),
            'trigger',
            $entityName,
            $entityFile,
            $errors,
        );

        $readPolicies = $this->mergeSection(
            array_map(static fn (ParsedSections $s): array => $s->readPolicies, $contributions),
            'readPolicy',
            $entityName,
            $entityFile,
            $errors,
        );

        $writePolicies = $this->mergeSection(
            array_map(static fn (ParsedSections $s): array => $s->writePolicies, $contributions),
            'writePolicy',
            $entityName,
            $entityFile,
            $errors,
        );

        return [
            'sections' => new ParsedSections($fields, $edges, $queries, $actions, $triggers, $readPolicies, $writePolicies),
            'errors' => $errors,
        ];
    }

    /**
     * @template T of Contributed
     *
     * @param list<array<string, T>> $contributions
     * @param list<SpecError>        $errors
     *
     * @return array<string, T>
     */
    private function mergeSection(
        array $contributions,
        string $section,
        string $entityName,
        string $entityFile,
        array &$errors,
    ): array {
        $merged = [];

        foreach ($contributions as $members) {
            foreach ($members as $name => $member) {
                $existing = $merged[$name] ?? null;

                if (null !== $existing) {
                    $errors[] = new SpecError(
                        'pattern.collision',
                        sprintf(
                            '%s declares %s "%s" from both %s and %s. Patterns are sealed: to change it, stop using the pattern.',
                            $entityName,
                            $section,
                            $name,
                            $existing->declaredIn()->describe(),
                            $member->declaredIn()->describe(),
                        ),
                        $entityFile,
                        sprintf('/%ss/%s', $section, $name),
                    );

                    continue;
                }

                $merged[$name] = $member;
            }
        }

        return $merged;
    }
}
