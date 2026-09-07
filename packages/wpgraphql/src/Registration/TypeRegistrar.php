<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Registration;

use BackedEnum;
use DateTimeInterface;
use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\WPGraphQL\Manifest\ConnectionEntry;
use Eleph\WPGraphQL\Manifest\FieldEncoding;
use Eleph\WPGraphQL\Manifest\FieldEntry;
use Eleph\WPGraphQL\Manifest\GraphQLType;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\ObjectTypeEntry;
use Eleph\WPGraphQL\Manifest\QueryFieldEntry;
use Eleph\WPGraphQL\Resolver\Connections;
use RuntimeException;

/**
 * Registers the manifest with WPGraphQL.
 *
 * Deliberately the thinnest thing in the package: every decision was made when the
 * manifest was compiled, so all that remains is translating value objects into the
 * arrays WPGraphQL expects. Keeping it this thin is what lets the interesting part be
 * tested without WordPress at all.
 */
final readonly class TypeRegistrar
{
    public function __construct(
        private Manifest $manifest,
        private EntityGateway $gateway,
        private Connections $connections = new Connections(),
        /**
         * Only a project with declared value types needs one, so it is optional — but
         * absent when one is needed, the field says so by name rather than handing
         * WPGraphQL an object it cannot serialise.
         */
        private ?ProcessorRegistry $processors = null,
    ) {
    }

    /**
     * Hook this on `graphql_register_types`.
     */
    public function register(): void
    {
        foreach ($this->enumConfigs() as $name => $config) {
            register_graphql_enum_type($name, $config);
        }

        foreach ($this->objectConfigs() as $name => $config) {
            register_graphql_object_type($name, $config);
        }

        foreach ($this->connectionConfigs() as $config) {
            register_graphql_connection($config);
        }

        foreach ($this->rootFieldConfigs() as $config) {
            register_graphql_field('RootQuery', $config['name'], $config['field']);
        }
    }

    /**
     * The registration arrays, separated from the calls so they can be inspected and
     * tested without a WordPress installation.
     *
     * Typed precisely rather than as array<string, mixed>, so that the WPGraphQL stubs
     * can prove the registration is well-formed instead of us asserting it is.
     *
     * @return array<string, array{values: array<string, array{value: string}>}>
     */
    public function enumConfigs(): array
    {
        $configs = [];

        foreach ($this->manifest->enums as $enum) {
            $values = [];

            foreach ($enum->values as $name => $value) {
                $values[$name] = ['value' => $value];
            }

            $configs[$enum->name] = ['values' => $values];
        }

        return $configs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function objectConfigs(): array
    {
        $configs = [];

        foreach ($this->manifest->objects as $object) {
            $configs[$object->name] = [
                'description' => $object->description ?? '',
                'fields' => $this->fieldConfigs($object),
            ];
        }

        return $configs;
    }

    /**
     * Connections, from an entity and from the root alike.
     *
     * A root collection is a connection rather than a list. A table needs to page and
     * to say "1-20 of 347", and a `list_of` with `first`/`after` args offers neither —
     * the arguments would be there and mean nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function connectionConfigs(): array
    {
        $configs = [];

        foreach ($this->manifest->objects as $object) {
            foreach ($object->connections as $connection) {
                $configs[] = $this->connection(
                    $connection->fromType,
                    $connection->toType,
                    $connection->name,
                    $connection->description ?? '',
                    $this->edgeResolver($connection),
                );
            }
        }

        foreach ($this->manifest->roots as $root) {
            $entity = $root->entity;

            $configs[] = $this->connection(
                'RootQuery',
                $root->type,
                $root->collection(),
                sprintf('Every %s.', $root->type),
                fn (mixed $source, array $args): array => $this->connections->resolve(
                    $this->gateway->all($entity),
                    $args,
                ),
            );
        }

        foreach ($this->manifest->queries as $query) {
            if (!$query->isCollection) {
                continue;
            }

            $configs[] = [
                ...$this->connection(
                    'RootQuery',
                    $query->type,
                    $query->field,
                    $query->description ?? '',
                    $this->queryResolver($query),
                ),
                // The query's own arguments sit alongside the paging ones WPGraphQL adds.
                'connectionArgs' => $this->args($query->args),
            ];
        }

        return $configs;
    }

    /**
     * An edge connection resolves by calling the accessor and paging the lazy query it
     * returns.
     *
     * WPGraphQL's own connection resolvers know how to page posts and terms, and an
     * entity is neither. Supplying one is also what makes an inverse work at all: the
     * accessor is the only thing that knows the edge is being read backwards.
     */
    private function edgeResolver(ConnectionEntry $connection): callable
    {
        $accessor = $connection->accessor;

        return function (mixed $source, array $args) use ($accessor): array {
            if (!is_object($source) || !method_exists($source, $accessor)) {
                throw new RuntimeException(sprintf(
                    'A connection resolved against something with no %s(). The manifest and the generated entities have drifted; run `eleph generate`.',
                    $accessor,
                ));
            }

            $query = $source->{$accessor}();

            if (!$query instanceof EntityQuery) {
                throw new RuntimeException(sprintf('%s() did not return an EntityQuery.', $accessor));
            }

            return $this->connections->resolve($query, $args);
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(
        string $from,
        string $to,
        string $field,
        string $description,
        ?callable $resolve = null,
    ): array {
        return [
            'fromType' => $from,
            'toType' => $to,
            'fromFieldName' => $field,
            'description' => $description,
            ...(null === $resolve ? [] : ['resolve' => $resolve]),
            // WPGraphQL supplies pageInfo, edges and nodes; totalCount it does not.
            // A table wants it, and the lazy query counts without hydrating, so it
            // costs one query rather than the whole set.
            'connectionFields' => [
                'totalCount' => [
                    'type' => 'Int',
                    'description' => 'How many match, ignoring pagination.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fieldConfigs(ObjectTypeEntry $object): array
    {
        $fields = [];

        foreach ($object->fields as $field) {
            $fields[$field->name] = [
                'type' => $field->type->toConfig(),
                'description' => $field->description ?? '',
                'resolve' => $this->resolver($field),
            ];
        }

        return $fields;
    }

    /**
     * The resolver is the convention made executable: call the accessor the generator
     * emitted for this field, on the entity the parent resolver produced, and encode
     * the result the way the manifest said this field travels.
     *
     * The encoding is not optional dressing. The read model is exactly typed, so
     * `getCreatedAt()` hands back a DateTimeImmutable where the client was promised a
     * String, and handing that straight to WPGraphQL fails the whole query.
     */
    private function resolver(FieldEntry $field): callable
    {
        $accessor = $field->accessor;
        $encoding = $field->encoding;
        $valueType = $field->valueType;
        $name = $field->name;

        return function (object $source) use ($accessor, $encoding, $valueType, $name): mixed {
            /** @var mixed $value */
            $value = $source->{$accessor}();

            // Null short-circuits everywhere else in the framework; it does here too.
            if (null === $value) {
                return null;
            }

            return $this->encode($value, $encoding, $valueType, $name);
        };
    }

    /**
     * One domain value, in the shape the manifest promised.
     */
    private function encode(mixed $value, FieldEncoding $encoding, ?string $valueType, string $field): mixed
    {
        return match ($encoding) {
            FieldEncoding::Value => $value,
            FieldEncoding::Datetime => $value instanceof DateTimeInterface
                ? $value->format(DateTimeInterface::ATOM)
                : $value,
            FieldEncoding::BackedEnum => $value instanceof BackedEnum ? $value->value : $value,
            FieldEncoding::Json => is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value,
            FieldEncoding::Processor => $this->unwind($value, $valueType, $field),
        };
    }

    /**
     * A declared value type, back to the primitive it is stored as.
     *
     * The write processor already owns that conversion for the mutation path, so
     * reusing it is what keeps a Money reading back as the same Int it was written as.
     */
    private function unwind(mixed $value, ?string $valueType, string $field): mixed
    {
        if (null === $valueType || null === $this->processors || !$this->processors->has($valueType)) {
            throw new RuntimeException(sprintf(
                'Field "%s" holds the declared type %s, which travels as its backing primitive. Pass a ProcessorRegistry to the GraphQL plugin so it can reach %s\'s write processor.',
                $field,
                $valueType ?? 'a value type',
                $valueType ?? 'that type',
            ));
        }

        return $this->processors->write($valueType)->write($value);
    }

    /**
     * The way in, for a single entity by id.
     *
     * Its collection counterpart is registered as a connection instead — see
     * connectionConfigs() — because a table needs paging and a total, not a list.
     *
     * @return list<array{name: string, field: array<string, mixed>}>
     */
    public function rootFieldConfigs(): array
    {
        $configs = [];

        foreach ($this->manifest->roots as $root) {
            $entity = $root->entity;

            $configs[] = [
                'name' => $root->single(),
                'field' => [
                    'type' => $root->type,
                    'description' => sprintf('One %s by id.', $root->type),
                    'args' => ['id' => ['type' => ['non_null' => 'ID']]],
                    'resolve' => function (mixed $source, array $args) use ($entity): ?object {
                        $id = $args['id'] ?? null;

                        return is_string($id) || is_int($id)
                            ? $this->gateway->find($entity, EntityId::of($id))
                            : null;
                    },
                ],
            ];

        }

        foreach ($this->manifest->queries as $query) {
            if ($query->isCollection) {
                continue;
            }

            $configs[] = [
                'name' => $query->field,
                'field' => [
                    'type' => $query->type,
                    'description' => $query->description ?? '',
                    'args' => $this->args($query->args),
                    'resolve' => fn (mixed $source, array $args): ?object => $this->gateway
                        ->runQuery($query->entity, $query->query, $args)
                        ->first(),
                ],
            ];
        }

        return $configs;
    }

    private function queryResolver(QueryFieldEntry $query): callable
    {
        return fn (mixed $source, array $args): array => $this->connections->resolve(
            $this->gateway->runQuery($query->entity, $query->query, $args),
            $args,
        );
    }

    /**
     * @param array<string, GraphQLType> $args
     *
     * @return array<string, array<string, mixed>>
     */
    private function args(array $args): array
    {
        $configs = [];

        foreach ($args as $name => $type) {
            $configs[$name] = ['type' => $type->toConfig()];
        }

        return $configs;
    }

    /**
     * @return list<ConnectionEntry>
     */
    public function connections(): array
    {
        $connections = [];

        foreach ($this->manifest->objects as $object) {
            foreach ($object->connections as $connection) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }
}
