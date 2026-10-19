using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage.Write;

/// <summary>Change some fields of a row.</summary>
/// <param name="Entity">The entity of the row.</param>
/// <param name="Id">The row's id.</param>
/// <param name="Values">Only the fields that changed, in primitive form.</param>
public sealed record Update(string Entity, EntityId Id, IReadOnlyDictionary<string, object?> Values) : IWriteOperation
{
    /// <inheritdoc />
    public IIdentifier Target => Id;
}
