using Elephentity.Runtime.Storage;

namespace Elephentity.Sqlite.Manifest;

/// <summary>
/// Where one edge physically lives. Ports <c>Eleph\SQLite\Sql\EdgePlacement</c>.
/// </summary>
/// <remarks>
/// Derived once and shared: the builder places the edge, and the query compiler and the writes use the placement to answer
/// "linked to". Two derivations would be two chances to disagree about the same edge.
/// </remarks>
/// <param name="Entity">The entity that declares the edge.</param>
/// <param name="Edge">The edge's name.</param>
/// <param name="Target">The entity the edge points at.</param>
/// <param name="Relation">The shape of the relation.</param>
/// <param name="Table">The table carrying the link: the far side, this side, or a join table.</param>
/// <param name="LocalColumn">The column pointing back at the declaring entity.</param>
/// <param name="TargetColumn">Join tables only: the column pointing at the target.</param>
/// <param name="TargetTable">The table rows of the target entity live in.</param>
public sealed record SqliteEdgePlacement(
    string Entity,
    string Edge,
    string Target,
    RelationKind Relation,
    string Table,
    string LocalColumn,
    string? TargetColumn = null,
    string TargetTable = "")
{
    /// <summary>Whether the link lives in a join table.</summary>
    public bool UsesJoinTable => TargetColumn is not null;

    /// <summary>
    /// Whether the link column sits on the declaring entity's own table.
    /// </summary>
    /// <remarks>
    /// When it does, finding what a Post links to means reading Post's own row rather than filtering the target: a different
    /// query shape entirely.
    /// </remarks>
    public bool KeyIsLocal => !UsesJoinTable && Relation.KeyIsLocal();
}
