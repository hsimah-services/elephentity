<?php

declare(strict_types=1);

namespace Eleph\Cli;

use Eleph\Schema\Integration\IntegrationDefinition;
use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\Spec\RawSpec;
use Eleph\Schema\Spec\SpecKind;
use Eleph\Schema\Storage\StorageRuleRegistry;
use Eleph\Schema\Wire\IntegrationCodec;
use Eleph\Schema\Wire\StorageRulesCodec;
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
     * @param list<string>   $drivers  Storage drivers some installed builder can generate for.
     * @param list<RawSpec>  $patterns Patterns some installed builder ships, pooled from `describe`.
     * @param list<RawSpec>  $types    Types some installed builder ships, pooled from `describe`.
     */
    private function __construct(
        public IntegrationRegistry $integrations,
        public array $drivers,
        public array $patterns = [],
        public array $types = [],
        public StorageRuleRegistry $storageRules = new StorageRuleRegistry(),
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
        $patterns = [];
        $types = [];
        $storageRules = [];

        /** @var mixed $declared */
        foreach ($targets as $target => $declared) {
            if (!is_array($declared)) {
                continue;
            }

            /** @var array<string, mixed> $provides */
            $provides = $declared;
            $target = (string) $target;

            foreach (self::integrationsIn($provides, $target) as $definition) {
                $definitions[] = $definition;
            }

            $declaredDrivers = self::driversIn($provides);

            foreach ($declaredDrivers as $driver) {
                $drivers[$driver] = true;
            }

            foreach (self::specsIn($provides, $target, 'patterns', SpecKind::Pattern) as $pattern) {
                $patterns[] = $pattern;
            }

            foreach (self::specsIn($provides, $target, 'types', SpecKind::Type) as $type) {
                $types[] = $type;
            }

            // Storage rules are the driver's own declaration, not the compiler's, so
            // they land against every driver this target declares rather than against
            // the target's name.
            $storage = $provides['storage'] ?? null;

            if (null !== $storage) {
                if (!is_array($storage)) {
                    throw new RuntimeException(sprintf('The %s builder declared "storage" as something other than an object.', $target));
                }

                try {
                    /** @var array<string, mixed> $storage */
                    $rules = StorageRulesCodec::decode($target, $storage);
                } catch (WireException $exception) {
                    throw new RuntimeException(sprintf(
                        'The %s builder declared storage rules unusably: %s',
                        $target,
                        $exception->getMessage(),
                    ), previous: $exception);
                }

                foreach ($declaredDrivers as $driver) {
                    $storageRules[$driver] = $rules;
                }
            }
        }

        $names = array_keys($drivers);
        sort($names);

        return new self(new IntegrationRegistry(...$definitions), $names, $patterns, $types, new StorageRuleRegistry($storageRules));
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

    /**
     * Patterns or types a builder ships, as the same document shape `SpecLoader` would
     * have parsed off disk — the wire shape is the spec document, as JSON, so pooling
     * needs no parser of its own and the result gets `SchemaValidator` and everything
     * after it unchanged.
     *
     * @param array<string, mixed> $provides
     *
     * @return list<RawSpec>
     */
    private static function specsIn(array $provides, string $target, string $key, SpecKind $kind): array
    {
        $declared = $provides[$key] ?? [];

        if (!is_array($declared)) {
            throw new RuntimeException(sprintf('The %s builder declared "%s" as something other than an object.', $target, $key));
        }

        $specs = [];

        /** @var mixed $data */
        foreach ($declared as $name => $data) {
            if (!is_string($name) || !is_array($data)) {
                throw new RuntimeException(sprintf('The %s builder declared an unnamed %s.', $target, $kind->value));
            }

            if (!array_key_exists($kind->value, $data)) {
                throw new RuntimeException(sprintf(
                    'The %s builder declared %s "%s" without a "%s" key.',
                    $target,
                    $kind->value,
                    $name,
                    $kind->value,
                ));
            }

            /** @var array<string, mixed> $data */
            $specs[] = new RawSpec($kind, sprintf('%s:%s/%s.yml', $target, (string) $kind->directory(), $name), $data);
        }

        return $specs;
    }
}
