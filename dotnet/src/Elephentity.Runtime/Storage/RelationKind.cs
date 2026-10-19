namespace Elephentity.Runtime.Storage;

/// <summary>
/// The relation an edge describes, as storage sees it.
/// </summary>
/// <remarks>
/// A mirror of the spec's own enum, for the same reason the PHP runtime mirrors it: the runtime
/// must not depend on the compiler, which is build-time and does not ship. Only the two questions
/// storage asks are here; deriving a relation from a cardinality and a reverse uniqueness is a
/// build-time act, and by the time a manifest is loaded that decision has been written down.
/// </remarks>
public enum RelationKind
{
    /// <summary>One row on each side.</summary>
    OneToOne,

    /// <summary>Many rows here point at one there.</summary>
    ManyToOne,

    /// <summary>One row here is pointed at by many there.</summary>
    OneToMany,

    /// <summary>Many on both sides, through a join table.</summary>
    ManyToMany,
}

/// <summary>What storage asks of a <see cref="RelationKind" />.</summary>
public static class RelationKindExtensions
{
    /// <summary>Many-to-many is the only relation needing a join table.</summary>
    /// <param name="relation">The relation.</param>
    /// <returns><see langword="true" /> for many-to-many.</returns>
    public static bool NeedsJoinTable(this RelationKind relation) => relation == RelationKind.ManyToMany;

    /// <summary>Whether the foreign key sits on this entity's table rather than the target's.</summary>
    /// <param name="relation">The relation.</param>
    /// <returns><see langword="true" /> for one-to-one and many-to-one.</returns>
    public static bool KeyIsLocal(this RelationKind relation) =>
        relation is RelationKind.OneToOne or RelationKind.ManyToOne;
}
