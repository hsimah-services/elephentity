using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage.Write;

/// <summary>Create a row.</summary>
/// <param name="Entity">The entity to create a row of.</param>
/// <param name="PendingId">The placeholder the new row's real id will replace.</param>
/// <param name="Values">The row's values in primitive form; processors have already run.</param>
public sealed record Insert(string Entity, PendingId PendingId, IReadOnlyDictionary<string, object?> Values) : IWriteOperation
{
    /// <inheritdoc />
    public IIdentifier Target => PendingId;
}
