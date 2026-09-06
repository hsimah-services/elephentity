<?php

declare(strict_types=1);

namespace Eleph\Schema\Pattern;

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Spec\SpecReader;

/**
 * Merges what patterns declare with what an entity supplies.
 *
 * Every applied pattern contributes its parameters into one map, so a consumer reads
 * the keys it knows rather than looking up a pattern by name — which would mean
 * renaming a pattern silently disabled whatever depended on it. Two patterns declaring
 * the same key is a compile error, so a value in the resolved map has exactly one
 * source.
 *
 * Defaults are applied here, so nothing downstream has to reason about absent keys.
 */
final readonly class ConfigResolver
{
    public function __construct(private ConfigValues $values = new ConfigValues())
    {
    }

    /**
     * @param list<PatternDefinition> $patterns The entity's applied patterns.
     * @param list<SpecError>         $errors
     *
     * @return array<string, mixed>
     */
    public function resolve(
        array $patterns,
        ?SpecReader $configure,
        string $entityName,
        string $entityFile,
        array &$errors,
    ): array {
        /** @var array<string, ConfigParameter> $declared */
        $declared = [];

        /** @var array<string, string> $declaredBy */
        $declaredBy = [];

        foreach ($patterns as $pattern) {
            foreach ($pattern->config as $name => $parameter) {
                if (isset($declaredBy[$name])) {
                    $errors[] = new SpecError(
                        'config.collision',
                        sprintf(
                            'Patterns %s and %s both declare configuration "%s". A resolved value must have one source.',
                            $declaredBy[$name],
                            $pattern->name,
                            $name,
                        ),
                        $entityFile,
                    );

                    continue;
                }

                $declared[$name] = $parameter;
                $declaredBy[$name] = $pattern->name;
            }
        }

        $supplied = $this->supplied($patterns, $configure, $declaredBy, $entityName, $entityFile, $errors);

        return $this->values->resolve(
            $declared,
            $supplied,
            $entityName,
            $entityFile,
            '/configure',
            $errors,
        );
    }

    /**
     * @param list<PatternDefinition> $patterns
     * @param array<string, string>   $declaredBy
     * @param list<SpecError>         $errors
     *
     * @return array<string, mixed>
     */
    private function supplied(
        array $patterns,
        ?SpecReader $configure,
        array $declaredBy,
        string $entityName,
        string $entityFile,
        array &$errors,
    ): array {
        if (null === $configure) {
            return [];
        }

        $applied = [];

        foreach ($patterns as $pattern) {
            $applied[$pattern->name] = $pattern;
        }

        $supplied = [];

        foreach ($configure->all() as $patternName => $rawValues) {
            if (!is_array($rawValues)) {
                continue;
            }

            /** @var array<string, mixed> $rawValues */
            $values = new SpecReader($rawValues);

            if (!isset($applied[$patternName])) {
                $errors[] = new SpecError(
                    'config.unusedPattern',
                    sprintf(
                        '%s configures "%s" but does not use it. Add it to `use:`, or drop the configuration.',
                        $entityName,
                        $patternName,
                    ),
                    $entityFile,
                    sprintf('/configure/%s', $patternName),
                );

                continue;
            }

            foreach ($values->all() as $key => $value) {
                $pointer = sprintf('/configure/%s/%s', $patternName, $key);
                $parameter = $applied[$patternName]->config[$key] ?? null;

                if (null !== $parameter && ($declaredBy[$key] ?? null) !== $patternName) {
                    // Reachable only alongside a collision, which is already reported.
                    continue;
                }

                unset($pointer);

                $supplied[$key] = $value;
            }
        }

        return $supplied;
    }
}
