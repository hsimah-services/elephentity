using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage.Write;

/// <summary>Remove a row.</summary>
/// <param name="Entity">The entity of the row.</param>
/// <param name="Id">The row's id.</param>
public sealed record Delete(string Entity, EntityId Id) : IWriteOperation
{
    /// <inheritdoc />
    public IIdentifier Target => Id;
}
