<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\Schema;

/**
 * Compiles the schema into everything the GraphQL layer needs to register.
 *
 * Pure, and therefore the part worth testing: given a schema, the API surface it
 * produces is fully determined. Whether WPGraphQL then accepts those registrations is
 * a thin matter of calling its functions.
 */
final readonly class ManifestBuilder
{
    public function __construct(private ?TypeMapper $types = null)
    {
    }

    public function build(Schema $schema): Manifest
    {
        $types = $this->types ?? new TypeMapper($schema);

        $objects = [];
        $enums = [];
        $mutations = [];

        foreach ($schema->types as $type) {
            if ($type->isEnum()) {
                $enums[$type->name] = $this->enum($type->name, $type->values ?? []);
            }
        }

        foreach ($schema->entities as $entity) {
            $objects[$entity->name] = $this->object($schema, $types, $entity);

            foreach ($entity->fields as $field) {
                if (Primitive::Enum !== $field->type->primitive) {
                    continue;
                }

                $enum = $field->enum;

                if (null === $enum || !$enum->isInline()) {
                    continue;
                }

                $name = (string) $types->enumName($entity, $field);
                $enums[$name] = $this->enum($name, $enum->inlineValues ?? []);
            }

            foreach ($this->mutations($types, $entity) as $mutation) {
                $mutations[$mutation->name] = $mutation;
            }
        }

        ksort($objects);
        ksort($enums);
        ksort($mutations);

        return new Manifest($objects, $enums, $mutations);
    }

    private function object(Schema $schema, TypeMapper $types, EntityDefinition $entity): ObjectTypeEntry
    {
        // Every entity has an implicit id, and it is exposed as an opaque ID rather
        // than an Int so that a later move to UUIDv7 is invisible to clients.
        $fields = [
            'id' => new FieldEntry('id', new GraphQLType('ID', nonNull: true), 'getId'),
        ];

        foreach ($entity->fields as $field) {
            $fields[$field->name] = new FieldEntry(
                $field->name,
                $types->forField($entity, $field),
                'get' . ucfirst($field->name),
                $field->description,
            );
        }

        $connections = [];

        foreach ($entity->edges as $edge) {
            $target = $schema->entity($edge->to);

            if (null === $target) {
                continue;
            }

            if (Cardinality::One === $edge->cardinality) {
                $fields[$edge->name] = new FieldEntry(
                    $edge->name,
                    new GraphQLType($target->name),
                    'get' . ucfirst($edge->name),
                    $edge->description,
                );

                continue;
            }

            $connections[$edge->name] = new ConnectionEntry(
                $edge->name,
                $entity->name,
                $target->name,
                $edge->name,
                $edge->name,
                $edge->description,
            );
        }

        return new ObjectTypeEntry(
            $entity->name,
            $entity->name,
            $fields,
            $connections,
            $entity->description,
        );
    }

    /**
     * @param list<string> $values
     */
    private function enum(string $name, array $values): EnumTypeEntry
    {
        $members = [];

        foreach ($values as $value) {
            // GraphQL enum values are conventionally SCREAMING_SNAKE_CASE; the stored
            // value stays exactly as the spec wrote it.
            $members[strtoupper($value)] = $value;
        }

        return new EnumTypeEntry($name, $members);
    }

    /**
     * @return list<MutationEntry>
     */
    private function mutations(TypeMapper $types, EntityDefinition $entity): array
    {
        $creatable = [];
        $updatable = [];

        foreach ($entity->fields as $field) {
            $creatable[$field->name] = $types->forInput($entity, $field);

            if ($field->immutable) {
                // Write-once: settable on create, absent from update entirely, which
                // is the same rule the mutator enforces by having no setter.
                continue;
            }

            $updatable[$field->name] = new GraphQLType(
                $types->forField($entity, $field)->name,
            );
        }

        $mutations = [
            new MutationEntry(
                'create' . $entity->name,
                MutationEntry::CREATE,
                $entity->name,
                $creatable,
                description: sprintf('Create a %s.', $entity->name),
            ),
            new MutationEntry(
                'update' . $entity->name,
                MutationEntry::UPDATE,
                $entity->name,
                ['id' => new GraphQLType('ID', nonNull: true), ...$updatable],
                description: sprintf('Update a %s.', $entity->name),
            ),
        ];

        foreach ($entity->actions as $action) {
            $inputs = ['id' => new GraphQLType('ID', nonNull: true)];

            foreach ($action->arguments as $argument) {
                $inputs[$argument->name] = $types->forArgument($argument);
            }

            $mutations[] = new MutationEntry(
                $action->name . $entity->name,
                MutationEntry::ACTION,
                $entity->name,
                $inputs,
                $action->name,
                $action->description ?? sprintf('Run %s on a %s.', $action->name, $entity->name),
            );
        }

        return $mutations;
    }
}
