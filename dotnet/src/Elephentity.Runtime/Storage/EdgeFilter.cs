using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage;

/// <summary>
/// Constrain a result set to what one or more entities are linked to along an edge, or,
/// reversed, to the rows whose edge points at them.
/// </summary>
/// <remarks>
/// <para>
/// Edges cannot be expressed as field filters: the foreign key column belongs to no declared
/// field, and a many-to-many link lives in a table the spec never names. So the port says "linked
/// to", and the adaptor, which knows whether that is a column or a join, decides how to ask.
/// </para>
/// <para>
/// <b>The edge is always named from the side that declares it.</b> <c>Inventory.item</c> is the
/// only edge in the schema whether you are asking "what item is this entry for" or "which entries
/// are for this item"; the second is the same edge read backwards, not a second edge.
/// </para>
/// <para>
/// Which way each reads, with <c>PageItem.page</c> (to-one, declared on PageItem) and <c>Post.tags</c>
/// (to-many, declared on Post). The ids always belong to the side the filter starts from, and the
/// result is always rows of the other side:
/// </para>
/// <code>
/// // The page this item sits on: start at PageItem 7, get Page rows.
/// Criteria.For("Page").LinkedTo(EdgeFilter.Along("PageItem", "page", itemId));
/// // The items of a page: start at Page 3, get PageItem rows.
/// Criteria.For("PageItem").LinkedTo(EdgeFilter.Back("PageItem", "page", pageId));
/// // A post's tags: start at Post 1, get Tag rows.
/// Criteria.For("Tag").LinkedTo(EdgeFilter.Along("Post", "tags", postId));
/// // The posts with a tag: start at Tag 5, get Post rows.
/// Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "tags", tagId));
/// </code>
/// <para>
/// So <c>Along</c> goes from the declaring entity to the edge's target, and <c>Back</c> from the target to
/// the declaring entity, whatever the cardinality. "Items of a page" is <c>Back</c>, because the edge is
/// declared on the item.
/// </para>
/// <para>
/// Several parents in one filter mean <em>any of them</em>, which is how one query serves fifty
/// posts asking for their comments. Separate filters on a criteria remain conjunctive, as field
/// filters are.
/// </para>
/// </remarks>
public sealed record EdgeFilter
{
    /// <summary>
    /// The column an adaptor projects to say which parent a row belongs to. Only present when the
    /// filter names more than one parent, since that is the only case where the caller cannot
    /// already tell.
    /// </summary>
    public const string ParentColumn = "__parent";

    private EdgeFilter(string entity, string edge, bool reversed, IIdentifier[] from)
    {
        if (from.Length == 0)
        {
            throw new ArgumentException("An edge filter needs at least one parent.", nameof(from));
        }

        Entity = entity;
        Edge = edge;
        Reversed = reversed;
        From = from;
    }

    /// <summary>The entity that declares the edge.</summary>
    public string Entity { get; }

    /// <summary>The edge's name.</summary>
    public string Edge { get; }

    /// <summary>Read the edge backwards: rows of <see cref="Entity" /> pointing at <see cref="From" />.</summary>
    public bool Reversed { get; }

    /// <summary>The parents the rows are linked to; any of them matches.</summary>
    public IReadOnlyList<IIdentifier> From { get; }

    /// <summary>What these rows of <paramref name="entity" /> are linked to along <paramref name="edge" />.</summary>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="edge">The edge.</param>
    /// <param name="from">The parents; at least one.</param>
    /// <returns>The filter.</returns>
    public static EdgeFilter Along(string entity, string edge, params IIdentifier[] from) =>
        new(entity, edge, false, from);

    /// <summary>
    /// Which rows of <paramref name="entity" /> point at these along <paramref name="edge" />: the same
    /// edge, read from the far end.
    /// </summary>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="edge">The edge.</param>
    /// <param name="from">The targets; at least one.</param>
    /// <returns>The filter.</returns>
    public static EdgeFilter Back(string entity, string edge, params IIdentifier[] from) =>
        new(entity, edge, true, from);

    /// <summary>Whether the adaptor must project <see cref="ParentColumn" /> so results can be grouped.</summary>
    /// <returns><see langword="true" /> when there is more than one parent.</returns>
    public bool NeedsParentColumn() => From.Count > 1;
}
