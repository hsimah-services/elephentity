<?php

declare(strict_types=1);

namespace Eleph\Schema\Integration;

/**
 * The integrations available to a project.
 *
 * Assembled by the composition root — the CLI, which knows which packages are
 * installed — and handed to the compiler. The compiler therefore validates against
 * whatever exists without ever naming one.
 */
final readonly class IntegrationRegistry
{
    /** @var array<string, IntegrationDefinition> */
    private array $integrations;

    public function __construct(IntegrationDefinition ...$integrations)
    {
        $byName = [];

        foreach ($integrations as $integration) {
            $byName[$integration->name] = $integration;
        }

        ksort($byName);

        $this->integrations = $byName;
    }

    public function has(string $name): bool
    {
        return isset($this->integrations[$name]);
    }

    public function get(string $name): ?IntegrationDefinition
    {
        return $this->integrations[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->integrations);
    }

    public function describeAvailable(): string
    {
        return [] === $this->integrations
            ? 'none are installed'
            : implode(', ', $this->names());
    }
}
