using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage.Write;

/// <summary>Detach one entity from another along a named edge, or clear the edge.</summary>
/// <param name="Entity">The entity that declares the edge.</param>
/// <param name="Edge">The edge.</param>
/// <param name="From">The row the edge is declared on.</param>
/// <param name="To">
/// The row to detach, or <see langword="null" /> to clear the edge entirely, which is how a
/// wholesale replacement starts.
/// </param>
public sealed record Unlink(string Entity, string Edge, IIdentifier From, IIdentifier? To = null) : IWriteOperation
{
    /// <inheritdoc />
    public IIdentifier Target => From;
}
