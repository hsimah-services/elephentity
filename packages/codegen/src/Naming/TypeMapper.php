<?php

declare(strict_types=1);

namespace PheFr\Codegen\Naming;

use DateTimeImmutable;
use LogicException;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Schema\Ir\ArgumentDefinition;
use PheFr\Schema\Ir\EntityDefinition;
use PheFr\Schema\Ir\FieldDefinition;
use PheFr\Schema\Ir\Primitive;
use PheFr\Schema\Ir\Schema;
use PheFr\Schema\Ir\TypeReference;

/**
 * Maps spec types onto PHP types.
 *
 * One lookup table, no escape hatch — which is the reason the primitive set is closed.
 */
final readonly class TypeMapper
{
    public function __construct(
        private Schema $schema,
        private Names $names,
    ) {
    }

    /**
     * The PHP type of a field, without its nullability marker.
     */
    public function forField(EntityDefinition $entity, FieldDefinition $field): string
    {
        if (Primitive::Enum === $field->type->primitive) {
            $enum = $field->enum;

            if (null === $enum) {
                throw new LogicException(sprintf(
                    'Enum field %s.%s reached codegen without values; validation should have caught it.',
                    $entity->name,
                    $field->name,
                ));
            }

            return $enum->isInline()
                ? $this->names->inlineEnum($entity, $field)
                : $this->names->enum((string) $enum->declaredType);
        }

        return $this->forReference($field->type);
    }

    public function forArgument(ArgumentDefinition $argument): string
    {
        return $this->forReference($argument->type);
    }

    /**
     * A field's type as it appears in a signature, nullability included.
     */
    public function signature(EntityDefinition $entity, FieldDefinition $field): string
    {
        $type = $this->forField($entity, $field);

        return $field->nullable ? '?' . $type : $type;
    }

    private function forReference(TypeReference $reference): string
    {
        $primitive = $reference->primitive;

        if (null !== $primitive) {
            return $this->fromPrimitive($primitive);
        }

        $name = (string) $reference->declaredType;
        $declared = $this->schema->type($name);

        if (null === $declared) {
            throw new LogicException(sprintf(
                'Unknown type "%s" reached codegen; validation should have caught it.',
                $name,
            ));
        }

        // A declared type carrying values is an enum, and the generator owns its class.
        // Anything else is a value object the application writes and we merely name.
        return $declared->isEnum()
            ? $this->names->enum($name)
            : $this->names->valueClass($name);
    }

    private function fromPrimitive(Primitive $primitive): string
    {
        return match ($primitive) {
            Primitive::String, Primitive::Text => 'string',
            Primitive::Int => 'int',
            Primitive::Float => 'float',
            Primitive::Bool => 'bool',
            Primitive::Datetime => DateTimeImmutable::class,
            Primitive::Id => EntityId::class,
            Primitive::Json => 'array',
            Primitive::Enum => throw new LogicException(
                'Enums are resolved from their field, not from the primitive alone.',
            ),
        };
    }
}
