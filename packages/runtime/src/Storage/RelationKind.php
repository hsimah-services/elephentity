<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

/**
 * The relation an edge describes, as storage sees it.
 *
 * A mirror of the spec's own enum, for the same reason `Managed` and `TriggerPhase` are
 * mirrored: the runtime must not depend on the schema package, which is build-time and
 * does not ship. This one is the case that made the rule bite — a compiled storage
 * manifest contains `RelationKind::OneToMany` as a literal, so *loading* it dragged the
 * spec compiler into production on every request of every WordPress project.
 *
 * Only the two questions storage asks are here. Deriving a relation from a cardinality
 * and a reverse uniqueness is a build-time act and stays in the IR; by the time a
 * manifest is loaded that decision has been made and written down.
 */
enum RelationKind
{
    case OneToOne;
    case ManyToOne;
    case OneToMany;
    case ManyToMany;

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
