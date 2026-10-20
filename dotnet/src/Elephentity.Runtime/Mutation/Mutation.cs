using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Mutation;

/// <summary>
/// One entity's pending changes: the buffer that records them and the context that reads them back.
/// </summary>
/// <remarks>
/// Both interfaces on one object because they are two views of the same state. Setters write into it,
/// verifiers read from it, and neither touches storage until commit.
/// </remarks>
public sealed class Mutation : IMutableMutationContext
{
    private static readonly IReadOnlyDictionary<string, object?> NoValues = new Dictionary<string, object?>();

    private readonly Dictionary<string, object?> changes = [];
    private readonly Dictionary<string, PendingEdge> edges = [];
    private readonly IReadOnlyDictionary<string, object?> original;
    private EntityId? resolvedId;

    /// <summary>A mutation of one row.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="target">The row; a <see cref="PendingId" /> for a create.</param>
    /// <param name="original">The entity as it was, in domain form. Empty on create.</param>
    /// <param name="actions">The actions requested.</param>
    /// <param name="originalEntity">The hydrated entity as it was; <see langword="null" /> on create.</param>
    public Mutation(
        string entity,
        IIdentifier target,
        IReadOnlyDictionary<string, object?>? original = null,
        IReadOnlyList<ActionCall>? actions = null,
        object? originalEntity = null)
    {
        Entity = entity;
        Target = target;
        this.original = original ?? NoValues;
        Actions = actions ?? [];
        OriginalEntity = originalEntity;
    }

    /// <inheritdoc />
    public string Entity { get; }

    /// <inheritdoc />
    public IReadOnlyList<ActionCall> Actions { get; }

    /// <inheritdoc />
    public object? OriginalEntity { get; }

    /// <inheritdoc />
    public IIdentifier Target { get; }

    /// <inheritdoc />
    public IIdentifier Id => resolvedId ?? Target;

    /// <inheritdoc />
    public bool IsCreate => !Target.IsPersisted;

    /// <summary>
    /// Records the real id once a create's insert has flushed, before post-commit side effects.
    /// </summary>
    /// <remarks>
    /// <see cref="Target" /> stays the original <see cref="PendingId" /> throughout, because
    /// <see cref="IsCreate" /> depends on that. Existing-row mutations already have an <see cref="EntityId" />.
    /// </remarks>
    /// <param name="id">The id storage assigned.</param>
    public void ResolveId(EntityId id) => resolvedId = id;

    /// <inheritdoc />
    public void Assign(string field, object? value) => changes[field] = value;

    /// <inheritdoc />
    public bool IsChanged(string field) => changes.ContainsKey(field);

    /// <inheritdoc />
    public object? Original(string field) => original.TryGetValue(field, out var value) ? value : null;

    /// <summary>
    /// Falls back to the original for untouched fields, so a verifier always sees what the row will actually
    /// hold rather than only what this mutation names.
    /// </summary>
    /// <param name="field">The field.</param>
    /// <returns>The pending value.</returns>
    public object? Pending(string field) => changes.TryGetValue(field, out var value) ? value : Original(field);

    /// <inheritdoc />
    public IReadOnlyDictionary<string, object?> Changes() => changes;

    /// <inheritdoc />
    public IEdgeMutation Edge(string edge)
    {
        if (!edges.TryGetValue(edge, out var pending))
        {
            pending = new PendingEdge(edge);
            edges[edge] = pending;
        }

        return pending;
    }

    /// <inheritdoc />
    public IReadOnlyList<IIdentifier> PendingEdge(string edge) =>
        edges.TryGetValue(edge, out var pending) ? pending.Added() : [];

    /// <inheritdoc />
    public bool IsEdgeChanged(string edge) => edges.TryGetValue(edge, out var pending) && !pending.IsEmpty();

    /// <summary>The edges this mutation actually changes, in the order they were first touched.</summary>
    /// <returns>The pending edges.</returns>
    public IReadOnlyDictionary<string, PendingEdge> EdgeChanges() =>
        edges.Where(pair => !pair.Value.IsEmpty()).ToDictionary(pair => pair.Key, pair => pair.Value);

    /// <summary>Whether committing this would do nothing: an update that changes no field and no edge.</summary>
    /// <returns><see langword="true" /> for a no-op update.</returns>
    public bool IsEmpty() => !IsCreate && changes.Count == 0 && EdgeChanges().Count == 0;

    /// <summary>Rows this mutation links to that have yet to be written.</summary>
    /// <returns>The pending identifiers, in edge order.</returns>
    public IReadOnlyList<IIdentifier> Dependencies() =>
        [.. EdgeChanges().Values.SelectMany(edge => edge.Unresolved())];
}
