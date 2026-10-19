using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage.Write;

/// <summary>
/// Attach one entity to another along a named edge.
/// </summary>
/// <remarks>
/// The port says what the link <em>is</em>, never how it is stored: whether that becomes a foreign
/// key update or a row in a join table depends on the relation, and only the adaptor knows the
/// physical schema. Keeping that decision below the port is what stops SQL shapes leaking into
/// the unit of work.
/// </remarks>
/// <param name="Entity">The entity that declares the edge.</param>
/// <param name="Edge">The edge.</param>
/// <param name="From">The row the edge is declared on.</param>
/// <param name="To">The row it points at.</param>
public sealed record Link(string Entity, string Edge, IIdentifier From, IIdentifier To) : IWriteOperation
{
    /// <inheritdoc />
    public IIdentifier Target => From;
}
