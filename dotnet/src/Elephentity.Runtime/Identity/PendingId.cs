using System.Globalization;

namespace Elephentity.Runtime.Identity;

/// <summary>
/// A placeholder for a row this unit of work is about to create.
/// </summary>
/// <remarks>
/// Server-generated ids mean a commit that creates a Post and its Comments must insert the
/// Post, read back its id, then insert the Comments. Until that happens the Comments still
/// need something to point at, and that is this. The unit of work orders writes by dependency
/// and resolves each placeholder to a real <see cref="EntityId" /> as its target is flushed.
/// Identity here is reference identity: two pending rows are the same row only if they are the
/// same object, because there is nothing else yet to compare.
/// </remarks>
public sealed class PendingId : IIdentifier
{
    private static long counter;

    private readonly long sequence;

    /// <summary>Creates a placeholder for a row of <paramref name="entity" />.</summary>
    /// <param name="entity">The entity the row will belong to.</param>
    public PendingId(string entity)
    {
        Entity = entity;
        sequence = Interlocked.Increment(ref counter);
    }

    /// <summary>The entity the pending row will belong to.</summary>
    public string Entity { get; }

    /// <inheritdoc />
    public bool IsPersisted => false;

    /// <inheritdoc />
    public bool Equals(IIdentifier? other) => ReferenceEquals(other, this);

    /// <inheritdoc />
    public override string ToString() => $"pending:{Entity}#{sequence.ToString(CultureInfo.InvariantCulture)}";
}
