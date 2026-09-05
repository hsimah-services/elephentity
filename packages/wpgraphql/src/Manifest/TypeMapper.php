<?php

declare(strict_types=1);

namespace PheFr\WPGraphQL\Manifest;

use PheFr\Schema\Ir\ArgumentDefinition;
use PheFr\Schema\Ir\EntityDefinition;
use PheFr\Schema\Ir\FieldDefinition;
use PheFr\Schema\Ir\Primitive;
use PheFr\Schema\Ir\Schema;
use PheFr\Schema\Ir\TypeReference;

/**
 * Maps spec types onto GraphQL types.
 *
 * A declared value type is exposed as its backing primitive rather than a custom
 * scalar: Money is an int over the wire. A custom scalar would need serialise and
 * parse functions the spec does not carry, and inventing one per value type would put
 * a second, unvalidated conversion between the client and the write processor that
 * already owns that job.
 */
final readonly class TypeMapper
{
    public function __construct(private Schema $schema)
    {
    }

    public function forField(EntityDefinition $entity, FieldDefinition $field): GraphQLType
    {
        return new GraphQLType(
            $this->name($entity, $field),
            // Nullable in the spec is nullable over the wire. `required` describes
            // creating a row, not reading one, so it says nothing here.
            nonNull: !$field->nullable,
        );
    }

    /**
     * A mutation input is nullable when the argument is, and when the field it feeds
     * is not required on create.
     */
    public function forInput(EntityDefinition $entity, FieldDefinition $field): GraphQLType
    {
        return new GraphQLType(
            $this->name($entity, $field),
            nonNull: $field->required && !$field->nullable,
        );
    }

    public function forArgument(ArgumentDefinition $argument): GraphQLType
    {
        return new GraphQLType(
            $this->fromReference($argument->type),
            nonNull: !$argument->nullable,
        );
    }

    /**
     * The GraphQL enum type name for a field, whether declared or inline.
     */
    public function enumName(EntityDefinition $entity, FieldDefinition $field): ?string
    {
        $enum = $field->enum;

        if (null === $enum) {
            return null;
        }

        return $enum->isInline()
            ? $entity->name . ucfirst($field->name)
            : (string) $enum->declaredType;
    }

    private function name(EntityDefinition $entity, FieldDefinition $field): string
    {
        if (Primitive::Enum === $field->type->primitive) {
            return (string) $this->enumName($entity, $field);
        }

        return $this->fromReference($field->type);
    }

    private function fromReference(TypeReference $reference): string
    {
        $primitive = $reference->primitive;

        if (null === $primitive) {
            $declared = $this->schema->type((string) $reference->declaredType);

            if (null !== $declared && $declared->isEnum()) {
                return (string) $reference->declaredType;
            }

            // A value type travels as whatever it is stored as.
            return $this->fromPrimitive($declared->primitive ?? Primitive::String);
        }

        return $this->fromPrimitive($primitive);
    }

    private function fromPrimitive(Primitive $primitive): string
    {
        return match ($primitive) {
            Primitive::Int => 'Int',
            Primitive::Float => 'Float',
            Primitive::Bool => 'Boolean',
            Primitive::Id => 'ID',
            // Dates and JSON travel as strings: WPGraphQL ships no scalar for either,
            // and a bespoke one would be a conversion the spec cannot describe.
            Primitive::String, Primitive::Text, Primitive::Datetime, Primitive::Json => 'String',
            Primitive::Enum => 'String',
        };
    }
}
