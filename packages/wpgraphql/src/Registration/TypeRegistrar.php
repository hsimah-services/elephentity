<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Registration;

use Eleph\WPGraphQL\Manifest\ConnectionEntry;
use Eleph\WPGraphQL\Manifest\FieldEntry;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\ObjectTypeEntry;

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
    public function __construct(private Manifest $manifest)
    {
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
     * @return list<array<string, mixed>>
     */
    public function connectionConfigs(): array
    {
        $configs = [];

        foreach ($this->manifest->objects as $object) {
            foreach ($object->connections as $connection) {
                $configs[] = [
                    'fromType' => $connection->fromType,
                    'toType' => $connection->toType,
                    'fromFieldName' => $connection->name,
                    'description' => $connection->description ?? '',
                ];
            }
        }

        return $configs;
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
     * emitted for this field, on the entity the parent resolver produced.
     */
    private function resolver(FieldEntry $field): callable
    {
        $accessor = $field->accessor;

        return static fn (object $source): mixed => $source->{$accessor}();
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
