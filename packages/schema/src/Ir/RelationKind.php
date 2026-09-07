<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

use Eleph\Runtime\Storage\RelationKind as StorageRelation;

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

    /**
     * The same relation, as the runtime's own enum.
     *
     * The runtime cannot depend on this package — it is build-time and does not ship —
     * so storage has a mirror of this, and this is the one place that says which case
     * maps to which. A `match` rather than `Storage\RelationKind::from($this->name)`,
     * because a rename here should be a compile error there and not a runtime one.
     */
    public function forStorage(): StorageRelation
    {
        return match ($this) {
            self::OneToOne => StorageRelation::OneToOne,
            self::ManyToOne => StorageRelation::ManyToOne,
            self::OneToMany => StorageRelation::OneToMany,
            self::ManyToMany => StorageRelation::ManyToMany,
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
