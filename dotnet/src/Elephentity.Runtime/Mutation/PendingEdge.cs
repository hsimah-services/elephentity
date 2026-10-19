using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Mutation;

/// <summary>
/// Pending changes to one edge, recorded until the commit resolves them.
/// </summary>
/// <remarks>
/// Holds identifiers rather than entities, so a link can be made to a row that does not exist yet:
/// attaching a Comment to a Post being created in the same commit records a <see cref="PendingId" />,
/// which the unit of work substitutes once the Post is inserted.
/// </remarks>
public sealed class PendingEdge : IEdgeMutation
{
    private readonly List<IIdentifier> added = [];
    private readonly List<IIdentifier> removed = [];
    private List<IIdentifier>? replacement;

    /// <summary>Records changes to the edge called <paramref name="name" />.</summary>
    /// <param name="name">The edge.</param>
    public PendingEdge(string name)
    {
        Name = name;
    }

    /// <summary>The edge's name.</summary>
    public string Name { get; }

    /// <inheritdoc />
    public void Add(IIdentifier target) => added.Add(target);

    /// <inheritdoc />
    public void Remove(IIdentifier target) => removed.Add(target);

    /// <inheritdoc />
    public void Replace(IReadOnlyList<IIdentifier> targets)
    {
        // A wholesale replacement supersedes anything recorded before it.
        replacement = [.. targets];
        added.Clear();
        removed.Clear();
    }

    /// <summary>Whether nothing has been recorded.</summary>
    /// <returns><see langword="true" /> when the edge is untouched.</returns>
    public bool IsEmpty() => added.Count == 0 && removed.Count == 0 && replacement is null;

    /// <summary>What the edge will hold after the commit: the replacement if there is one, else the additions.</summary>
    /// <returns>The identifiers.</returns>
    public IReadOnlyList<IIdentifier> Added() => replacement ?? added;

    /// <summary>What the edge will lose.</summary>
    /// <returns>The identifiers.</returns>
    public IReadOnlyList<IIdentifier> Removed() => removed;

    /// <summary>Whether the edge is being replaced wholesale.</summary>
    /// <returns><see langword="true" /> after <see cref="Replace" />.</returns>
    public bool IsReplacement() => replacement is not null;

    /// <summary>Every row this edge points at that has yet to be written.</summary>
    /// <returns>The pending identifiers.</returns>
    public IReadOnlyList<IIdentifier> Unresolved() =>
        [.. Added().Concat(removed).Where(id => !id.IsPersisted)];
}
