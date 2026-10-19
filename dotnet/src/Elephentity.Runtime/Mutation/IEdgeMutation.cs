using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Mutation;

/// <summary>
/// Pending changes to one edge.
/// </summary>
/// <remarks>
/// Takes identifiers rather than entities so a unit of work can link rows that do not exist yet: the
/// Comment being attached to a Post in the same commit.
/// </remarks>
public interface IEdgeMutation
{
    /// <summary>Attach <paramref name="target" /> to the edge.</summary>
    /// <param name="target">The row to attach.</param>
    void Add(IIdentifier target);

    /// <summary>Detach <paramref name="target" /> from the edge.</summary>
    /// <param name="target">The row to detach.</param>
    void Remove(IIdentifier target);

    /// <summary>Replace the edge's contents wholesale.</summary>
    /// <param name="targets">What the edge should hold.</param>
    void Replace(IReadOnlyList<IIdentifier> targets);
}
