using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage.Write;

/// <summary>
/// What a batch produced: chiefly, the real ids assigned to inserted rows.
/// </summary>
/// <remarks>
/// Keyed by object identity because a pending id has no value to key on, which is the whole reason
/// it exists.
/// </remarks>
public sealed class WriteResult
{
    private readonly Dictionary<PendingId, EntityId> assigned = new(ReferenceEqualityComparer.Instance);

    /// <summary>Records the real id given to a pending row.</summary>
    /// <param name="pending">The placeholder.</param>
    /// <param name="id">The id storage assigned.</param>
    public void Assign(PendingId pending, EntityId id) => assigned[pending] = id;

    /// <summary>Whether storage assigned an id to <paramref name="pending" />.</summary>
    /// <param name="pending">The placeholder.</param>
    /// <returns><see langword="true" /> when it did.</returns>
    public bool WasAssigned(PendingId pending) => assigned.ContainsKey(pending);

    /// <summary>The real id of a row the batch inserted.</summary>
    /// <param name="pending">The placeholder.</param>
    /// <returns>The id.</returns>
    /// <exception cref="KeyNotFoundException">The batch never inserted that row.</exception>
    public EntityId IdFor(PendingId pending) =>
        assigned.TryGetValue(pending, out var id)
            ? id
            : throw new KeyNotFoundException($"No id was assigned to {pending}.");
}
