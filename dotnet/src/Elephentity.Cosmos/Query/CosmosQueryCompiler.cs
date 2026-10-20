using Elephentity.Cosmos.Manifest;
using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos.Query;

/// <summary>
/// Turns a <see cref="Criteria" /> into a parameterised Cosmos SQL query.
/// </summary>
/// <remarks>
/// <para>
/// Values always become parameters. Property names cannot be, so they are resolved against the entity's layout rather
/// than escaped: an unknown field is a bug in the caller, and refusing it is safer than quoting whatever arrived.
/// They are written in bracket form (<c>c["name"]</c>) and validated as plain identifiers by the manifest.
/// </para>
/// <para>
/// Every query is one logical partition (the adaptor sets the partition key on the request) and starts with
/// <c>c.type = @type</c>, because documents of every entity and every edge share the container.
/// </para>
/// <para>
/// Paging resumes by <em>keyset</em>: the page is the rows strictly after the cursor's position in the total order, and
/// Cosmos has no row-value comparison, so the position is an OR-expanded tuple comparison. An offset is accepted for
/// compatibility and never produced.
/// </para>
/// </remarks>
internal sealed class CosmosQueryCompiler(CosmosStorageManifest manifest)
{
    /// <summary>A query for the rows of a criteria.</summary>
    /// <param name="criteria">What to fetch. Its limit and cursor are ignored: the caller decides them.</param>
    /// <param name="links">What each edge filter was resolved to.</param>
    /// <param name="take">How many rows to ask for, or <see langword="null" /> for all of them.</param>
    /// <param name="resume">Return only rows strictly after this position.</param>
    /// <param name="offset">Skip this many rows; used only when there is no <paramref name="resume" />.</param>
    /// <returns>The query.</returns>
    public CosmosQuery Select(
        Criteria criteria,
        IReadOnlyList<LinkConstraint> links,
        int? take = null,
        KeysetCursor? resume = null,
        long offset = 0)
    {
        var parameters = new Parameters();
        var layout = manifest.Entity(criteria.Entity);
        var conditions = Conditions(criteria, layout, links, parameters);
        var orders = criteria.WithStableOrder().Orders;

        if (resume is not null)
        {
            conditions.Add(Resume(layout, orders, resume, parameters));
        }

        var top = take is { } rows && offset == 0 ? $"TOP {parameters.Add(rows)} " : string.Empty;
        var text = $"SELECT {top}* FROM c WHERE {string.Join(" AND ", conditions)} ORDER BY {Order(layout, orders)}";

        if (take is { } limit && offset > 0 && resume is null)
        {
            text += $" OFFSET {parameters.Add(offset)} LIMIT {parameters.Add(limit)}";
        }

        return new CosmosQuery(text, parameters.All);
    }

    /// <summary>A query for how many rows match.</summary>
    /// <param name="criteria">What to count.</param>
    /// <param name="links">What each edge filter was resolved to.</param>
    /// <returns>The query; its single result is the count.</returns>
    public CosmosQuery Count(Criteria criteria, IReadOnlyList<LinkConstraint> links)
    {
        var parameters = new Parameters();
        var layout = manifest.Entity(criteria.Entity);
        var conditions = Conditions(criteria, layout, links, parameters);

        return new CosmosQuery(
            $"SELECT VALUE COUNT(1) FROM c WHERE {string.Join(" AND ", conditions)}",
            parameters.All);
    }

    private static List<string> Conditions(
        Criteria criteria,
        CosmosEntityLayout layout,
        IReadOnlyList<LinkConstraint> links,
        Parameters parameters)
    {
        var conditions = new List<string> { $"c.type = {parameters.Add(layout.Entity)}" };

        foreach (var filter in criteria.Filters)
        {
            conditions.Add(Clause(Path(layout, filter.Field), filter, parameters));
        }

        foreach (var link in links)
        {
            conditions.Add($"ARRAY_CONTAINS({parameters.Add(link.Values.ToArray())}, {Expression(link.Property)})");
        }

        return conditions;
    }

    private static string Clause(string path, Filter filter, Parameters parameters)
    {
        switch (filter.Comparison)
        {
            case Comparison.IsNull:
                return $"(IS_NULL({path}) OR NOT IS_DEFINED({path}))";
            case Comparison.IsNotNull:
                return $"(IS_DEFINED({path}) AND NOT IS_NULL({path}))";
            case Comparison.In:
            case Comparison.NotIn:
                var values = filter.Value as IReadOnlyList<object> ?? [];

                // An empty set matches nothing, and its negation everything; say so rather than send an empty array.
                if (values.Count == 0)
                {
                    return filter.Comparison == Comparison.In ? "1 = 0" : "1 = 1";
                }

                var contains = $"ARRAY_CONTAINS({parameters.Add(values.ToArray())}, {path})";

                return filter.Comparison == Comparison.In ? contains : $"NOT {contains}";
            case Comparison.Contains:
                return $"CONTAINS({path}, {parameters.Add(filter.Value)}, false)";
            case Comparison.StartsWith:
                return $"STARTSWITH({path}, {parameters.Add(filter.Value)}, false)";
            case Comparison.Equal:
                return $"{path} = {parameters.Add(filter.Value)}";
            case Comparison.NotEqual:
                // A row without the property is not equal to the value, as in the memory driver and as "not equal"
                // reads; Cosmos alone would leave it out, because comparing an undefined property is undefined.
                return $"(NOT IS_DEFINED({path}) OR {path} != {parameters.Add(filter.Value)})";
            case Comparison.LessThan:
                return $"{path} < {parameters.Add(filter.Value)}";
            case Comparison.LessThanOrEqual:
                return $"{path} <= {parameters.Add(filter.Value)}";
            case Comparison.GreaterThan:
                return $"{path} > {parameters.Add(filter.Value)}";
            case Comparison.GreaterThanOrEqual:
                return $"{path} >= {parameters.Add(filter.Value)}";
            default:
                throw new InvalidOperationException($"Comparison {filter.Comparison} has no Cosmos form.");
        }
    }

    private static string Order(CosmosEntityLayout layout, IReadOnlyList<Order> orders) =>
        string.Join(
            ", ",
            orders.Select(order => $"{Path(layout, order.Field)} {(order.Direction == Direction.Descending ? "DESC" : "ASC")}"));

    /// <summary>
    /// The rows strictly after <paramref name="position" />, in the total order <paramref name="orders" />.
    /// </summary>
    /// <remarks>
    /// <c>(a, b, id) &gt; (x, y, z)</c> is <c>a &gt; x OR (a = x AND b &gt; y) OR (a = x AND b = y AND id &gt; z)</c>, with the
    /// comparison turned around for a descending term. Cosmos orders values of different types by type, with null
    /// before everything, so a null needs its own form: after a null, ascending, is every defined value; after a
    /// non-null, descending, includes the nulls, which sort last.
    /// </remarks>
    private static string Resume(
        CosmosEntityLayout layout,
        IReadOnlyList<Order> orders,
        KeysetCursor position,
        Parameters parameters)
    {
        if (position.SortValues.Count != orders.Count - 1)
        {
            throw new ArgumentException(
                $"The keyset cursor carries {position.SortValues.Count} sort value(s) but the criteria order by {orders.Count - 1}.",
                nameof(position));
        }

        object?[] values = [.. position.SortValues, position.Id.Raw()];
        var branches = new List<string>();

        for (var term = 0; term < orders.Count; term++)
        {
            var parts = new List<string>();

            for (var earlier = 0; earlier < term; earlier++)
            {
                parts.Add(Same(Path(layout, orders[earlier].Field), values[earlier], parameters));
            }

            parts.Add(After(Path(layout, orders[term].Field), orders[term].Direction == Direction.Descending, values[term], parameters));
            branches.Add(parts.Count == 1 ? parts[0] : $"({string.Join(" AND ", parts)})");
        }

        return $"({string.Join(" OR ", branches)})";
    }

    private static string Same(string path, object? value, Parameters parameters) =>
        value is null ? $"IS_NULL({path})" : $"{path} = {parameters.Add(value)}";

    private static string After(string path, bool descending, object? value, Parameters parameters) =>
        (descending, value) switch
        {
            (false, null) => $"(IS_DEFINED({path}) AND NOT IS_NULL({path}))",
            (true, null) => "1 = 0",
            (false, _) => $"{path} > {parameters.Add(value)}",
            (true, _) => $"({path} < {parameters.Add(value)} OR IS_NULL({path}))",
        };

    private static string Path(CosmosEntityLayout layout, string field) => Expression(layout.Property(field));

    private static string Expression(string property) => property == DocumentProperties.Id ? "c.id" : $"c[\"{property}\"]";

    /// <summary>Allocates parameter names and keeps their values.</summary>
    private sealed class Parameters
    {
        private readonly List<KeyValuePair<string, object?>> values = [];

        public IReadOnlyList<KeyValuePair<string, object?>> All => values;

        public string Add(object? value)
        {
            var name = $"@p{values.Count}";
            values.Add(new KeyValuePair<string, object?>(name, value));

            return name;
        }
    }
}
