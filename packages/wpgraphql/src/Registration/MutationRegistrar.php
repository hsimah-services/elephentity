<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Registration;

use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\WPGraphQL\Manifest\GraphQLType;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\MutationEntry;
use Eleph\WPGraphQL\Relay\GlobalId;
use InvalidArgumentException;

/**
 * Registers create, update, delete and action mutations.
 *
 * Dynamic, not generated. WPGraphQL takes a closure for every resolver, so the whole
 * surface is a loop over the manifest — there is nothing here a generator would do
 * better, and a generated copy per entity would be one more tree to keep in step.
 */
final readonly class MutationRegistrar
{
    public function __construct(
        private Manifest $manifest,
        private EntityGateway $gateway,
    ) {
    }

    /**
     * Hook this on `graphql_register_types`.
     */
    public function register(): void
    {
        foreach ($this->configs() as $name => $config) {
            register_graphql_mutation($name, $config);
        }
    }

    /**
     * Separated from the calls so the surface can be inspected without WordPress.
     *
     * @return array<string, array<string, mixed>>
     */
    public function configs(): array
    {
        $configs = [];

        foreach ($this->manifest->mutations as $mutation) {
            $configs[$mutation->name] = [
                'description' => $mutation->description ?? '',
                'inputFields' => $this->inputs($mutation),
                'outputFields' => $this->outputs($mutation),
                'mutateAndGetPayload' => $this->resolver($mutation),
            ];
        }

        foreach ($this->manifest->roots as $root) {
            // Delete is not in the manifest's mutation list: it takes no fields beyond
            // an id, so there is nothing for the builder to derive from the spec.
            $configs['delete' . $root->type] = [
                'description' => sprintf('Delete a %s.', $root->type),
                'inputFields' => ['id' => ['type' => ['non_null' => 'ID']]],
                'outputFields' => ['deletedId' => ['type' => 'ID']],
                'mutateAndGetPayload' => function (array $input) use ($root): array {
                    $id = $this->identifier($input);
                    $this->gateway->delete($root->entity, $id);

                    // The global form, because it is the id the client cached under
                    // and so the one it has to evict.
                    return ['deletedId' => GlobalId::encode($root->type, (string) $id)];
                },
            ];
        }

        ksort($configs);

        return $configs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function inputs(MutationEntry $mutation): array
    {
        $fields = [];

        foreach ($mutation->inputs as $name => $type) {
            $fields[$name] = ['type' => $type->toConfig()];
        }

        return $fields;
    }

    /**
     * Every mutation hands back the row it touched, so a client can update its cache
     * without a second round trip.
     *
     * @return array<string, array<string, mixed>>
     */
    private function outputs(MutationEntry $mutation): array
    {
        $type = $this->typeFor($mutation->entity);

        return [
            lcfirst($type) => [
                'type' => $type,
                'resolve' => fn (array $payload): ?object => is_string($payload['id'] ?? null)
                    ? $this->gateway->find($mutation->entity, EntityId::of($payload['id']))
                    : null,
            ],
        ];
    }

    private function resolver(MutationEntry $mutation): callable
    {
        return function (array $input) use ($mutation): array {
            $id = match ($mutation->kind) {
                MutationEntry::CREATE => $this->gateway->create(
                    $mutation->entity,
                    $this->withoutId($mutation, $input),
                ),
                default => $this->identifier($input),
            };

            if (MutationEntry::UPDATE === $mutation->kind) {
                $this->gateway->update($mutation->entity, $id, $this->withoutId($mutation, $input));
            }

            if (MutationEntry::ACTION === $mutation->kind) {
                $this->gateway->runAction(
                    $mutation->entity,
                    (string) $mutation->method,
                    $id,
                    $this->withoutId($mutation, $input),
                );
            }

            return ['id' => (string) $id];
        };
    }

    /**
     * The id a mutation was pointed at.
     *
     * GraphQL hands ids over as strings; the value object is what everything below
     * expects, and rejecting an unusable one here beats a null reference later.
     *
     * @param array<array-key, mixed> $input
     */
    private function identifier(array $input): EntityId
    {
        // A client only ever holds the global form, so that is what arrives. A raw row
        // id still resolves, which keeps a hand-written mutation working.
        return GlobalId::entityId($input['id'] ?? null)
            ?? throw new InvalidArgumentException('This mutation needs an id.');
    }

    /**
     * Everything but the id, with any global id unwrapped.
     *
     * An edge is written by naming the row it points at, and the id the client has for
     * that row is the global one this type hands out. Passing it through untouched
     * would write a link to a row number nothing holds.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function withoutId(MutationEntry $mutation, array $input): array
    {
        unset($input['id']);

        $fields = [];

        foreach ($input as $name => $value) {
            if (is_string($name)) {
                $fields[$name] = $this->raw($mutation->inputs[$name] ?? null, $value);
            }
        }

        return $fields;
    }

    /**
     * Decoding is strict — anything that is not one of ours is passed through exactly
     * as it arrived — so a spec field genuinely typed `id` is untouched.
     */
    private function raw(?GraphQLType $declared, mixed $value): mixed
    {
        if ('ID' !== $declared?->name) {
            return $value;
        }

        return is_array($value)
            ? array_map(GlobalId::raw(...), $value)
            : GlobalId::raw($value);
    }

    private function typeFor(string $entity): string
    {
        foreach ($this->manifest->roots as $root) {
            if ($root->entity === $entity) {
                return $root->type;
            }
        }

        return $entity;
    }
}
