<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * The closed set of primitives.
 *
 * Closed is the point: every mapping a primitive drives — SQL column, PHP type,
 * GraphQL type, validation — is a lookup table in the generator, with no escape hatch
 * that would let an entity become unpredictable.
 */
enum Primitive: string
{
    case String = 'string';
    case Text = 'text';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Datetime = 'datetime';
    case Id = 'id';
    case Enum = 'enum';
    case Json = 'json';

    /**
     * Primitives a declared enum may be backed by.
     *
     * @return list<self>
     */
    public static function enumBackings(): array
    {
        return [self::String, self::Int];
    }

    /** Whether a field of this primitive is sized by maxLength. */
    public function isSized(): bool
    {
        return self::String === $this;
    }
}
