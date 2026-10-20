namespace Elephentity.Runtime.Storage;

/// <summary>
/// What to fetch. Immutable; the <c>With</c> and builder methods return modified copies.
/// </summary>
public sealed record Criteria
{
    private Criteria(
        string entity,
        IReadOnlyList<Filter> filters,
        IReadOnlyList<Order> orders,
        int? limit,
        ICursor? after,
        IReadOnlyList<EdgeFilter> links)
    {
        Entity = entity;
        Filters = filters;
        Orders = orders;
        Limit = limit;
        After = after;
        Links = links;
    }

    /// <summary>The entity to fetch.</summary>
    public string Entity { get; }

    /// <summary>Conjunctive field conditions.</summary>
    public IReadOnlyList<Filter> Filters { get; }

    /// <summary>The sort, in order of significance.</summary>
    public IReadOnlyList<Order> Orders { get; }

    /// <summary>The page size, or <see langword="null" /> for no paging.</summary>
    public int? Limit { get; }

    /// <summary>Where the page starts, or <see langword="null" /> for the first page.</summary>
    public ICursor? After { get; }

    /// <summary>Edge conditions, also conjunctive with the filters.</summary>
    public IReadOnlyList<EdgeFilter> Links { get; }

    /// <summary>What to fetch of <paramref name="entity" />, before any filtering, ordering or paging.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The criteria.</returns>
    public static Criteria For(string entity) => new(entity, [], [], null, null, []);

    /// <summary>These criteria narrowed by <paramref name="filter" />.</summary>
    /// <param name="filter">The condition to add.</param>
    /// <returns>The narrowed criteria.</returns>
    public Criteria Where(Filter filter) => new(Entity, [.. Filters, filter], Orders, Limit, After, Links);

    /// <summary>These criteria constrained by <paramref name="link" />.</summary>
    /// <param name="link">The edge condition to add.</param>
    /// <returns>The constrained criteria.</returns>
    public Criteria LinkedTo(EdgeFilter link) => new(Entity, Filters, Orders, Limit, After, [.. Links, link]);

    /// <summary>These criteria with <paramref name="order" /> added as the next sort term.</summary>
    /// <param name="order">The term to add.</param>
    /// <returns>The ordered criteria.</returns>
    public Criteria OrderBy(Order order) => new(Entity, Filters, [.. Orders, order], Limit, After, Links);

    /// <summary>
    /// A unique final order term makes offset walks deterministic on a fixed dataset: the sort
    /// ends in <c>id</c> ascending unless it already ends in <c>id</c>.
    /// </summary>
    /// <returns>The criteria with a stable order.</returns>
    public Criteria WithStableOrder() =>
        Orders.Count > 0 && Orders[^1].Field == "id" ? this : OrderBy(Order.Ascending("id"));

    /// <summary>One page of at most <paramref name="limit" /> rows, after <paramref name="after" />.</summary>
    /// <param name="limit">The page size.</param>
    /// <param name="after">The cursor the previous page returned, if any.</param>
    /// <returns>The paged criteria, with a stable order.</returns>
    public Criteria Take(int limit, ICursor? after = null) =>
        new(Entity, Filters, WithStableOrder().Orders, limit, after, Links);

    /// <summary>The same criteria without paging, for counting or for fetching everything.</summary>
    /// <returns>The unbounded criteria.</returns>
    public Criteria Unbounded() => new(Entity, Filters, Orders, null, null, Links);
}
