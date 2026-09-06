<?php

declare(strict_types=1);

namespace PheFr\Schema\Pattern;

use PheFr\Schema\Error\SpecError;
use PheFr\Schema\Ir\ConfigParameter;
use PheFr\Schema\Spec\SpecReader;

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

        $resolved = [];

        foreach ($declared as $name => $parameter) {
            if (array_key_exists($name, $supplied)) {
                $resolved[$name] = $supplied[$name];

                continue;
            }

            if ($parameter->hasDefault) {
                $resolved[$name] = $parameter->default;

                continue;
            }

            if ($parameter->nullable) {
                $resolved[$name] = null;
            }
        }

        ksort($resolved);

        return $resolved;
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

                if (null === $parameter) {
                    $errors[] = new SpecError(
                        'config.unknown',
                        sprintf(
                            'Pattern %s declares no configuration "%s". It accepts: %s.',
                            $patternName,
                            $key,
                            [] === $applied[$patternName]->config
                                ? 'nothing'
                                : implode(', ', array_keys($applied[$patternName]->config)),
                        ),
                        $entityFile,
                        $pointer,
                    );

                    continue;
                }

                if ($declaredBy[$key] !== $patternName) {
                    // Reachable only alongside a collision, which is already reported.
                    continue;
                }

                if (!$parameter->accepts($value)) {
                    $errors[] = new SpecError(
                        'config.invalidValue',
                        sprintf(
                            'Configuration "%s" expects %s.',
                            $key,
                            $parameter->describeExpectation(),
                        ),
                        $entityFile,
                        $pointer,
                    );

                    continue;
                }

                $supplied[$key] = $value;
            }
        }

        return $supplied;
    }
}
