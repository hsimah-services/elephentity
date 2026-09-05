<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * The relation an edge describes, derived from its cardinality and the uniqueness of
 * its reverse side. Storage follows from this and is never declared by hand.
 */
enum RelationKind
{
    case OneToOne;
    case ManyToOne;
    case OneToMany;
    case ManyToMany;

    public static function of(Cardinality $cardinality, bool $reverseIsUnique): self
    {
        return match (true) {
            Cardinality::One === $cardinality && $reverseIsUnique => self::OneToOne,
            Cardinality::One === $cardinality => self::ManyToOne,
            $reverseIsUnique => self::OneToMany,
            default => self::ManyToMany,
        };
    }

    /** Many-to-many is the only relation needing a join table. */
    public function needsJoinTable(): bool
    {
        return self::ManyToMany === $this;
    }

    /** Whether the foreign key sits on this entity's table rather than the target's. */
    public function keyIsLocal(): bool
    {
        return self::OneToOne === $this || self::ManyToOne === $this;
    }
}
