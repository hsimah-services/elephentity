<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\Schema;
use Eleph\WPGraphQL\Integration\WpGraphQL;
use RuntimeException;

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
        $roots = [];
        $queries = [];

        foreach ($schema->types as $type) {
            if ($type->isEnum()) {
                $enums[$type->name] = $this->enum($type->name, $type->values ?? []);
            }
        }

        foreach ($schema->entities as $entity) {
            $exposure = $entity->exposedVia(WpGraphQL::NAME);

            // Opt-in: an entity that says nothing is not in the graph at all.
            if (null === $exposure) {
                continue;
            }

            $name = is_string($exposure['singular'] ?? null) ? $exposure['singular'] : $entity->name;
            $plural = is_string($exposure['plural'] ?? null) ? $exposure['plural'] : $name;

            $objects[$name] = $this->object($schema, $types, $entity, $name);
            $roots[$name] = new RootFieldEntry($name, $plural, $entity->name);

            foreach ($entity->fields as $field) {
                if (Primitive::Enum !== $field->type->primitive) {
                    continue;
                }

                $enum = $field->enum;

                if (null === $enum || !$enum->isInline()) {
                    continue;
                }

                $enumName = (string) $types->enumName($entity, $field);
                $enums[$enumName] = $this->enum($enumName, $enum->inlineValues ?? []);
            }

            foreach ($this->mutations($types, $entity, $name) as $mutation) {
                $mutations[$mutation->name] = $mutation;
            }

            foreach ($this->queries($schema, $types, $entity) as $query) {
                $queries[$query->field] = $query;
            }
        }

        ksort($objects);
        ksort($enums);
        ksort($mutations);
        ksort($roots);
        ksort($queries);

        return new Manifest($objects, $enums, $mutations, $roots, $queries);
    }

    private function object(
        Schema $schema,
        TypeMapper $types,
        EntityDefinition $entity,
        string $name,
    ): ObjectTypeEntry {
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
                $this->encoding($schema, $field),
                $this->valueType($schema, $field),
            );
        }

        $connections = [];

        foreach ($entity->edges as $edge) {
            $target = $schema->entity($edge->to);
            $targetExposure = $target?->exposedVia(WpGraphQL::NAME);

            // An edge to an entity nobody exposed has nothing to point at.
            if (null === $target || null === $targetExposure) {
                continue;
            }

            $targetName = is_string($targetExposure['singular'] ?? null)
                ? $targetExposure['singular']
                : $target->name;

            if (Cardinality::One === $edge->cardinality) {
                $fields[$edge->name] = new FieldEntry(
                    $edge->name,
                    new GraphQLType($targetName),
                    'get' . ucfirst($edge->name),
                    $edge->description,
                );

                continue;
            }

            $connections[$edge->name] = new ConnectionEntry(
                $edge->name,
                $name,
                $targetName,
                $edge->name,
                $edge->name,
                $edge->description,
            );
        }

        return new ObjectTypeEntry(
            $name,
            $entity->name,
            $fields,
            $connections,
            $entity->description,
        );
    }

    /**
     * How the accessor's return value reaches the wire.
     *
     * Derived from the same field the wire type was derived from, so the two are
     * decided together: a datetime is declared String and formatted, an enum is
     * declared as its enum type and travels as its backing value, and a declared value
     * type travels as whatever it is stored as.
     */
    private function encoding(Schema $schema, FieldDefinition $field): FieldEncoding
    {
        $primitive = $field->type->primitive;

        if (null === $primitive) {
            $declared = $schema->type((string) $field->type->declaredType);

            if (null !== $declared && $declared->isEnum()) {
                return FieldEncoding::BackedEnum;
            }

            // A value type with no processors is an alias for its primitive, and the
            // generator types the accessor as the primitive, so nothing has to happen.
            return true === $declared?->hasProcessors
                ? FieldEncoding::Processor
                : $this->forPrimitive($declared->primitive ?? Primitive::String);
        }

        return $this->forPrimitive($primitive);
    }

    private function forPrimitive(Primitive $primitive): FieldEncoding
    {
        return match ($primitive) {
            Primitive::Datetime => FieldEncoding::Datetime,
            Primitive::Enum => FieldEncoding::BackedEnum,
            Primitive::Json => FieldEncoding::Json,
            default => FieldEncoding::Value,
        };
    }

    /**
     * The type whose processor unwinds the value, for the one encoding that needs one.
     */
    private function valueType(Schema $schema, FieldDefinition $field): ?string
    {
        return FieldEncoding::Processor === $this->encoding($schema, $field)
            ? (string) $field->type->declaredType
            : null;
    }

    /**
     * @return list<QueryFieldEntry>
     */
    private function queries(Schema $schema, TypeMapper $types, EntityDefinition $entity): array
    {
        $published = [];

        foreach ($entity->queries as $query) {
            $exposure = $query->exposedVia(WpGraphQL::NAME);

            if (null === $exposure) {
                continue;
            }

            $returns = $schema->entity($query->returns->type);
            $returnExposure = $returns?->exposedVia(WpGraphQL::NAME);

            if (null === $returns || null === $returnExposure) {
                // A published query returning a type nobody exposed has nothing to
                // hand back. Silently dropping it would leave a field missing from the
                // API with no explanation.
                throw new RuntimeException(sprintf(
                    '%s::%s is published to GraphQL but returns %s, which is not exposed. Expose it, or stop publishing the query.',
                    $entity->name,
                    $query->name,
                    $query->returns->type,
                ));
            }

            $args = [];

            foreach ($query->arguments as $argument) {
                $args[$argument->name] = $types->forArgument($argument);
            }

            $published[] = new QueryFieldEntry(
                field: is_string($exposure['field'] ?? null) ? $exposure['field'] : $query->name,
                type: is_string($returnExposure['singular'] ?? null)
                    ? $returnExposure['singular']
                    : $returns->name,
                isCollection: Cardinality::Many === $query->returns->cardinality,
                entity: $entity->name,
                query: $query->name,
                args: $args,
                description: $query->description,
            );
        }

        return $published;
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
    private function mutations(TypeMapper $types, EntityDefinition $entity, string $name): array
    {
        $creatable = [];
        $updatable = [];

        foreach ($entity->fields as $field) {
            // A managed field is filled at commit, so putting it on the input would
            // make every client invent a value the server is about to overwrite.
            if (null !== $field->managed) {
                continue;
            }

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
                'create' . $name,
                MutationEntry::CREATE,
                $entity->name,
                $creatable,
                description: sprintf('Create a %s.', $name),
            ),
            new MutationEntry(
                'update' . $name,
                MutationEntry::UPDATE,
                $entity->name,
                ['id' => new GraphQLType('ID', nonNull: true), ...$updatable],
                description: sprintf('Update a %s.', $name),
            ),
        ];

        foreach ($entity->actions as $action) {
            $inputs = ['id' => new GraphQLType('ID', nonNull: true)];

            foreach ($action->arguments as $argument) {
                $inputs[$argument->name] = $types->forArgument($argument);
            }

            $mutations[] = new MutationEntry(
                $action->name . $name,
                MutationEntry::ACTION,
                $entity->name,
                $inputs,
                $action->name,
                $action->description ?? sprintf('Run %s on a %s.', $action->name, $name),
            );
        }

        return $mutations;
    }
}
