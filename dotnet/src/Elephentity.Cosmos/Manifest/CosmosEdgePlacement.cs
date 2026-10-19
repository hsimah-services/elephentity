using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos.Manifest;

/// <summary>
/// Where one edge physically lives.
/// </summary>
/// <remarks>
/// <para>
/// Derived once and shared: the manifest builder places the edge, and the query compiler and the writes use the
/// placement to answer "linked to". Two derivations would be two chances to disagree about the same edge.
/// </para>
/// <para>
/// A relation's shape decides it, as in the SQL adaptors, with no choice offered to a human:
/// </para>
/// <list type="bullet">
/// <item><description><see cref="RelationKind.OneToOne" /> and <see cref="RelationKind.ManyToOne" /> keep the target's id in
/// <see cref="Property" /> of the <em>declaring</em> entity's document.</description></item>
/// <item><description><see cref="RelationKind.OneToMany" /> keeps the owner's id in <see cref="Property" /> of each <em>target</em>
/// document.</description></item>
/// <item><description><see cref="RelationKind.ManyToMany" /> is a join document per link, of type <see cref="JoinType" />,
/// with the two ids in <c>from</c> and <c>to</c>. Its id is derived from the pair, so linking twice is one
/// document, and unlinking a pair is a point delete that needs no query.</description></item>
/// </list>
/// </remarks>
/// <param name="Entity">The entity that declares the edge.</param>
/// <param name="Edge">The edge's name.</param>
/// <param name="Target">The entity the edge points at.</param>
/// <param name="Relation">The shape of the relation.</param>
/// <param name="Property">The property that carries the reference; ignored for a many-to-many edge.</param>
public sealed record CosmosEdgePlacement(
    string Entity,
    string Edge,
    string Target,
    RelationKind Relation,
    string Property = "")
{
    /// <summary>Whether the link lives in join documents.</summary>
    public bool UsesJoinDocuments => Relation.NeedsJoinTable();

    /// <summary>
    /// Whether the reference is a property of the declaring entity's own document.
    /// </summary>
    /// <remarks>
    /// When it is, finding what a Post links to means reading Post's own document rather than filtering the
    /// target: a different query shape entirely.
    /// </remarks>
    public bool KeyIsLocal => !UsesJoinDocuments && Relation.KeyIsLocal();

    /// <summary>The <c>type</c> of this edge's join documents: <c>__edge:Entity.edge</c>.</summary>
    public string JoinType => $"{DocumentProperties.EdgeTypePrefix}{Entity}.{Edge}";

    /// <summary>The id of the join document for one link: stable, so a link made twice is one document.</summary>
    /// <param name="from">The declaring row's id.</param>
    /// <param name="to">The target row's id.</param>
    /// <returns>The document id.</returns>
    public string JoinId(string from, string to) => $"{JoinType}|{from}|{to}";
}
