<?php

declare(strict_types=1);

namespace Eleph\GraphQL\Registration;

use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\MutationResult;
use Eleph\GraphQL\Manifest\GraphQLType;
use Eleph\GraphQL\Manifest\Manifest;
use Eleph\GraphQL\Manifest\MutationEntry;
use Eleph\GraphQL\Relay\GlobalId;
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
                    $id = $this->identifier($input, $root->type);
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
                'resolve' => fn (array $payload): ?object => ($payload['result'] ?? null) instanceof MutationResult
                    ? $payload['result']->entity
                    : null,
            ],
        ];
    }

    private function resolver(MutationEntry $mutation): callable
    {
        return function (array $input) use ($mutation): array {
            $result = match ($mutation->kind) {
                MutationEntry::CREATE => $this->gateway->create($mutation->entity, $this->withoutId($mutation, $input)),
                MutationEntry::UPDATE => $this->gateway->update($mutation->entity, $this->identifier($input, $this->typeFor($mutation->entity)), $this->withoutId($mutation, $input)),
                MutationEntry::ACTION => $this->gateway->runAction($mutation->entity, (string) $mutation->method, $this->identifier($input, $this->typeFor($mutation->entity)), $this->withoutId($mutation, $input)),
                default => throw new InvalidArgumentException('Unknown mutation kind.'),
            };

            return ['id' => (string) $result->id, 'result' => $result];
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
    private function identifier(array $input, string $type): EntityId
    {
        // A client only ever holds the global form, so that is what arrives. A raw row
        // id still resolves, which keeps a hand-written mutation working.
        return GlobalId::entityId($input['id'] ?? null, $type)
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
                $fields[$name] = $this->raw($mutation->inputs[$name] ?? null, $value, $this->inputType($mutation, $name));
            }
        }

        return $fields;
    }

    /**
     * Decoding is strict — anything that is not one of ours is passed through exactly
     * as it arrived — so a spec field genuinely typed `id` is untouched.
     */
    private function raw(?GraphQLType $declared, mixed $value, ?string $expectedType): mixed
    {
        if ('ID' !== $declared?->name) {
            return $value;
        }

        return is_array($value)
            ? array_map(static fn (mixed $id): mixed => GlobalId::raw($id, $expectedType), $value)
            : GlobalId::raw($value, $expectedType);
    }

    private function inputType(MutationEntry $mutation, string $name): ?string
    {
        if (MutationEntry::ACTION === $mutation->kind) {
            return null;
        }

        foreach ($this->manifest->objects as $object) {
            if ($object->entity !== $mutation->entity) {
                continue;
            }

            $fieldType = ($object->fields[$name] ?? null)?->type->name;
            if (null !== $fieldType && isset($this->manifest->objects[$fieldType])) {
                return $fieldType;
            }

            return ($object->connections[$name] ?? null)?->toType;
        }

        return null;
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
