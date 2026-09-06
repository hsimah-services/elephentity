<?php

declare(strict_types=1);

namespace Eleph\Schema\Integration;

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Pattern\ConfigValues;
use Eleph\Schema\Spec\SpecReader;

/**
 * Resolves what a project enables and what an entity opts into.
 *
 * Kept per integration rather than merged into one map, unlike pattern configuration.
 * Two integrations may reasonably both want a `prefix`, and there is no ambiguity
 * about whose it is — the answer is in the key above it.
 */
final readonly class IntegrationResolver
{
    public function __construct(
        private IntegrationRegistry $registry,
        private ConfigValues $values = new ConfigValues(),
    ) {
    }

    /**
     * Integrations the project speaks, with their project-level settings.
     *
     * @param list<SpecError> $errors
     *
     * @return array<string, array<string, mixed>>
     */
    public function forProject(?SpecReader $declared, string $file, array &$errors): array
    {
        $resolved = [];

        foreach ($this->supplied($declared) as $name => $settings) {
            if (!$this->registry->has($name)) {
                $errors[] = new SpecError(
                    'integration.unknown',
                    sprintf(
                        'Unknown integration "%s". Installed: %s.',
                        $name,
                        $this->registry->describeAvailable(),
                    ),
                    $file,
                    sprintf('/integrations/%s', $name),
                );

                continue;
            }

            $resolved[$name] = $this->values->resolve(
                $this->registry->get($name)->projectConfig ?? [],
                $settings,
                sprintf('Integration %s', $name),
                $file,
                sprintf('/integrations/%s', $name),
                $errors,
            );
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * Integrations this entity is exposed through, with its own settings.
     *
     * @param array<string, array<string, mixed>> $enabled What the project declared.
     * @param list<SpecError>                     $errors
     *
     * @return array<string, array<string, mixed>>
     */
    public function forEntity(
        ?SpecReader $declared,
        array $enabled,
        string $entityName,
        string $file,
        array &$errors,
    ): array {
        $resolved = [];

        foreach ($this->supplied($declared) as $name => $settings) {
            $pointer = sprintf('/integrations/%s', $name);

            if (!$this->registry->has($name)) {
                $errors[] = new SpecError(
                    'integration.unknown',
                    sprintf(
                        'Unknown integration "%s". Installed: %s.',
                        $name,
                        $this->registry->describeAvailable(),
                    ),
                    $file,
                    $pointer,
                );

                continue;
            }

            if (!array_key_exists($name, $enabled)) {
                // Exposure is opt-in twice on purpose: the project says what it speaks,
                // the entity says what it exposes. Skipping the first would let an
                // entity widen the project's surface on its own.
                $errors[] = new SpecError(
                    'integration.notEnabled',
                    sprintf(
                        '%s uses integration "%s", which the project does not enable. Add it to project.yml.',
                        $entityName,
                        $name,
                    ),
                    $file,
                    $pointer,
                );

                continue;
            }

            $resolved[$name] = $this->values->resolve(
                $this->registry->get($name)->entityConfig ?? [],
                $settings,
                sprintf('Integration %s', $name),
                $file,
                $pointer,
                $errors,
            );
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function supplied(?SpecReader $declared): array
    {
        if (null === $declared) {
            return [];
        }

        $supplied = [];

        foreach ($declared->all() as $name => $settings) {
            /** @var array<string, mixed> $settings */
            $supplied[$name] = is_array($settings) ? $settings : [];
        }

        return $supplied;
    }
}
