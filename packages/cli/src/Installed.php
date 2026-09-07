<?php

declare(strict_types=1);

namespace Eleph\Cli;

use Eleph\Schema\Integration\IntegrationDefinition;
use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\Wire\IntegrationCodec;
use Eleph\Schema\Wire\WireException;
use JsonException;
use RuntimeException;

/**
 * What this project's builders provide, asked for rather than assumed.
 *
 * The compiler cannot validate `integrations: { wpgraphql: { singular: … } }` until it
 * knows what keys `wpgraphql` accepts, and the package that owns that answer is the one
 * that also generates the GraphQL manifest. So the answer is fetched from the builders
 * before a spec is read — `eleph-codegen describe`, which resolves them and pools their
 * replies.
 *
 * This used to be a hardcoded list of one. That is why installing the CLI installed
 * WordPress and GraphQL support whether the project wanted them or not, and why a
 * project on another driver could not avoid them.
 *
 * A builder that provides neither an integration nor a driver is the normal case: a
 * language generator turns the IR into source and knows no platform.
 */
final readonly class Installed
{
    /**
     * @param list<string> $drivers Storage drivers some installed builder can generate for.
     */
    private function __construct(
        public IntegrationRegistry $integrations,
        public array $drivers,
    ) {
    }

    public static function describedBy(Codegen $codegen, string $projectRoot): self
    {
        try {
            return self::fromJson($codegen->capture(['describe', '--project', $projectRoot]));
        } catch (RuntimeException $exception) {
            // The upgrade path runs through here. A generator installed before describe
            // existed reports only that the subcommand is unknown, which says nothing
            // about what to do — and it is the first thing every existing project will
            // hit, because the handshake is what a stale one cannot answer.
            throw new RuntimeException(sprintf(
                "Could not ask the code generator what this project's builders provide.\n%s\n"
                . 'If that says the "describe" command is not defined, eleph-codegen predates '
                . 'the handshake: update it, and the builders with it.',
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    /**
     * Nothing installed, for a caller with no project to ask about.
     */
    public static function none(): self
    {
        return new self(new IntegrationRegistry(), []);
    }

    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The code generator described the builders unusably: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $targets = is_array($decoded) ? ($decoded['targets'] ?? null) : null;

        if (!is_array($targets)) {
            throw new RuntimeException('The code generator described no targets.');
        }

        $definitions = [];
        $drivers = [];

        /** @var mixed $declared */
        foreach ($targets as $target => $declared) {
            if (!is_array($declared)) {
                continue;
            }

            /** @var array<string, mixed> $provides */
            $provides = $declared;

            foreach (self::integrationsIn($provides, (string) $target) as $definition) {
                $definitions[] = $definition;
            }

            foreach (self::driversIn($provides) as $driver) {
                $drivers[$driver] = true;
            }
        }

        $names = array_keys($drivers);
        sort($names);

        return new self(new IntegrationRegistry(...$definitions), $names);
    }

    /**
     * Whether some installed builder can generate for this driver.
     */
    public function provides(string $driver): bool
    {
        return in_array($driver, $this->drivers, true);
    }

    public function describeDrivers(): string
    {
        return [] === $this->drivers ? 'none are installed' : implode(', ', $this->drivers);
    }

    /**
     * @param array<string, mixed> $provides
     *
     * @return list<IntegrationDefinition>
     */
    private static function integrationsIn(array $provides, string $target): array
    {
        $declared = $provides['integrations'] ?? [];

        if (!is_array($declared)) {
            throw new RuntimeException(sprintf('The %s builder declared "integrations" as something other than an object.', $target));
        }

        $definitions = [];

        /** @var mixed $definition */
        foreach ($declared as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                throw new RuntimeException(sprintf('The %s builder declared an unnamed integration.', $target));
            }

            try {
                /** @var array<string, mixed> $definition */
                $definitions[] = IntegrationCodec::decode($name, $definition);
            } catch (WireException $exception) {
                throw new RuntimeException(sprintf(
                    'The %s builder declared integration "%s" unusably: %s',
                    $target,
                    $name,
                    $exception->getMessage(),
                ), previous: $exception);
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $provides
     *
     * @return list<string>
     */
    private static function driversIn(array $provides): array
    {
        $declared = $provides['drivers'] ?? [];

        return is_array($declared)
            ? array_values(array_filter($declared, is_string(...)))
            : [];
    }
}
