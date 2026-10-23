using System.Globalization;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Sqlite.Manifest;

namespace Elephentity.Sqlite.Query;

/// <summary>
/// Turns a <see cref="Criteria" /> into a parameterised <c>SELECT</c>. Ports <c>Eleph\SQLite\Sql\QueryCompiler</c>.
/// </summary>
/// <remarks>
/// Values always become parameters. Identifiers cannot be parameterised, so column names are resolved against the table's own
/// schema rather than escaped: an unknown column is a bug in the caller, and refusing it outright is safer than quoting
/// whatever happened to arrive.
/// </remarks>
internal sealed class SqliteQueryCompiler(SqliteStorageManifest manifest)
{
    public SqliteQuery Select(SqliteTable table, Criteria criteria)
    {
        var bindings = new Bindings();
        var links = Links(table, criteria, bindings);
        var clauses = Where(table, criteria, bindings);

        clauses.AddRange(links.Clauses);

        // Ending in the id, even unbounded, so the same query always returns rows in the same order.
        var orders = criteria.WithStableOrder().Orders;

        if (criteria.Limit is not null && KeysetCursor.From(criteria.After) is { } position)
        {
            clauses.Add(After(criteria.Entity, table, orders, position, bindings));
        }

        var sql = $"SELECT {SqliteNames.Quote(table.Name)}.*{links.Projection} FROM {SqliteNames.Quote(table.Name)}"
            + links.Join
            + (clauses.Count == 0 ? string.Empty : " WHERE " + string.Join(" AND ", clauses))
            + OrderBy(criteria.Entity, table, criteria, orders)
            + Limit(criteria);

        return new SqliteQuery(sql, bindings.Values);
    }

    public SqliteQuery Count(SqliteTable table, Criteria criteria)
    {
        var bindings = new Bindings();
        var links = Links(table, criteria, bindings);
        var clauses = Where(table, criteria, bindings);

        clauses.AddRange(links.Clauses);

        return new SqliteQuery(
            $"SELECT COUNT(*) FROM {SqliteNames.Quote(table.Name)}"
            + links.Join
            + (clauses.Count == 0 ? string.Empty : " WHERE " + string.Join(" AND ", clauses)),
            bindings.Values);
    }

    /// <summary>
    /// Compile every "linked to" constraint into joins, where clauses, and (when more than one parent is named) the projection
    /// that says which parent a row came from.
    /// </summary>
    private LinkSql Links(SqliteTable table, Criteria criteria, Bindings bindings)
    {
        var joins = new System.Text.StringBuilder();
        var clauses = new List<string>();
        var projection = new System.Text.StringBuilder();
        var alias = 0;

        foreach (var link in criteria.Links)
        {
            var placement = manifest.Placement(link.Entity, link.Edge);
            var ids = string.Join(", ", link.From.Select(parent => bindings.Add(Raw(parent))));
            var parent = EdgeFilter.ParentColumn;

            if (KeyIsOnThisTable(placement, link))
            {
                // The key is a column on this very table, so no join is needed.
                clauses.Add($"{SqliteNames.Quote(table.Name)}.{SqliteNames.Quote(placement.LocalColumn)} IN ({ids})");

                if (link.NeedsParentColumn())
                {
                    projection.Append(CultureInfo.InvariantCulture, $", {SqliteNames.Quote(table.Name)}.{SqliteNames.Quote(placement.LocalColumn)} AS {SqliteNames.Quote(parent)}");
                }

                continue;
            }

            var on = SqliteNames.Quote($"l{++alias}");

            // On a join table the two columns swap roles when the edge is read backwards: whichever end we came from is the
            // one we filter on.
            var (joinToTarget, parentColumn) = placement.UsesJoinTable
                ? link.Reversed
                    ? (placement.LocalColumn, placement.TargetColumn!)
                    : (placement.TargetColumn!, placement.LocalColumn)
                : (placement.LocalColumn, placement.LocalColumn);

            joins.Append(CultureInfo.InvariantCulture, $" INNER JOIN {SqliteNames.Quote(placement.Table)} {on} ON {on}.{SqliteNames.Quote(joinToTarget)} = {SqliteNames.Quote(table.Name)}.\"id\"");

            clauses.Add(placement.UsesJoinTable
                ? $"{on}.{SqliteNames.Quote(parentColumn)} IN ({ids})"
                : $"{on}.\"id\" IN ({ids})");

            if (link.NeedsParentColumn())
            {
                var source = placement.UsesJoinTable ? SqliteNames.Quote(parentColumn) : "\"id\"";

                projection.Append(CultureInfo.InvariantCulture, $", {on}.{source} AS {SqliteNames.Quote(parent)}");
            }
        }

        return new LinkSql(joins.ToString(), clauses, projection.ToString());
    }

    /// <summary>
    /// Whether the link column sits on the table being queried, so no join is needed.
    /// </summary>
    /// <remarks>
    /// Reading an edge backwards swaps the answer, and only the answer: one side of a foreign key is the table holding it and
    /// the other is the table it points at, and which of those we are selecting from is the whole difference between the two
    /// directions. There is no second placement for an inverse for the same reason there is no second edge.
    /// </remarks>
    private static bool KeyIsOnThisTable(SqliteEdgePlacement placement, EdgeFilter link)
    {
        if (placement.UsesJoinTable)
        {
            return false;
        }

        return link.Reversed ? placement.KeyIsLocal : !placement.KeyIsLocal;
    }

    private List<string> Where(SqliteTable table, Criteria criteria, Bindings bindings)
    {
        var clauses = new List<string>();

        foreach (var filter in criteria.Filters)
        {
            clauses.Add(Clause($"{SqliteNames.Quote(table.Name)}.{SqliteNames.Quote(Resolve(criteria.Entity, table, filter.Field))}", filter, bindings));
        }

        return clauses;
    }

    private static string Clause(string column, Filter filter, Bindings bindings) => filter.Comparison switch
    {
        Comparison.IsNull => $"{column} IS NULL",
        Comparison.IsNotNull => $"{column} IS NOT NULL",
        Comparison.In or Comparison.NotIn => SetClause(column, filter, bindings),
        Comparison.Contains => $"{column} LIKE {bindings.Add("%" + EscapeLike(filter.Value) + "%")} ESCAPE '\\'",
        Comparison.StartsWith => $"{column} LIKE {bindings.Add(EscapeLike(filter.Value) + "%")} ESCAPE '\\'",
        _ => $"{column} {Operator(filter.Comparison)} {bindings.Add(Scalar(filter.Value))}",
    };

    private static string SetClause(string column, Filter filter, Bindings bindings)
    {
        var values = filter.Value is IReadOnlyList<object> list ? list : [filter.Value!];

        if (values.Count == 0)
        {
            // An empty IN () matches nothing (or everything, for NOT IN), so say that directly.
            return filter.Comparison == Comparison.In ? "1 = 0" : "1 = 1";
        }

        return $"{column} {(filter.Comparison == Comparison.In ? "IN" : "NOT IN")} ({string.Join(", ", values.Select(value => bindings.Add(Scalar(value))))})";
    }

    private static string Operator(Comparison comparison) => comparison switch
    {
        Comparison.Equal => "=",
        Comparison.NotEqual => "<>",
        Comparison.LessThan => "<",
        Comparison.LessThanOrEqual => "<=",
        Comparison.GreaterThan => ">",
        Comparison.GreaterThanOrEqual => ">=",
        _ => throw new InvalidOperationException($"Comparison {comparison.ToWire()} has no simple operator."),
    };

    private string OrderBy(string entity, SqliteTable table, Criteria criteria, IReadOnlyList<Order> orders)
    {
        var parts = orders
            .Select(order => $"{SqliteNames.Quote(table.Name)}.{SqliteNames.Quote(Resolve(entity, table, order.Field))} {(order.Direction == Direction.Descending ? "DESC" : "ASC")}")
            .ToList();

        if (criteria.Links.Any(link => link.NeedsParentColumn()))
        {
            parts.Add($"{SqliteNames.Quote(EdgeFilter.ParentColumn)} ASC");
        }

        return " ORDER BY " + string.Join(", ", parts);
    }

    private static string Limit(Criteria criteria)
    {
        if (criteria.Limit is not { } requested)
        {
            return string.Empty;
        }

        // A cap rather than trust: an unbounded page is how a lazy query stops being lazy. Callers wanting everything leave the
        // limit off and mean it.
        var limit = Math.Min(Math.Max(requested, 1), Page<Record>.MaxLimit);

        // A keyset cursor says where to resume in the WHERE clause, so it has no offset to add.
        var offset = KeysetCursor.From(criteria.After) is null ? OffsetCursor.From(criteria.After).Value : 0;

        // One extra row, discarded before the page is returned, so a next page is known without a second query.
        return offset == 0
            ? $" LIMIT {(limit + 1).ToString(CultureInfo.InvariantCulture)}"
            : $" LIMIT {(limit + 1).ToString(CultureInfo.InvariantCulture)} OFFSET {offset.ToString(CultureInfo.InvariantCulture)}";
    }

    /// <summary>
    /// The rows strictly after <paramref name="position" /> in the total order: its sort terms, then the id.
    /// </summary>
    /// <remarks>
    /// Written out as <c>a &gt; x OR (a = x AND b &gt; y) OR …</c> rather than a row-value comparison, because the terms may run in
    /// different directions and may be null. SQLite sorts <c>NULL</c> before every value, so ascending the rows after a null
    /// are those that are not null, and descending nothing is after it.
    /// </remarks>
    private string After(string entity, SqliteTable table, IReadOnlyList<Order> orders, KeysetCursor position, Bindings bindings)
    {
        if (position.SortValues.Count != orders.Count - 1)
        {
            throw new ArgumentException(
                $"The keyset cursor carries {position.SortValues.Count} sort value(s) but the criteria order by {orders.Count - 1}.",
                nameof(position));
        }

        var terms = orders
            .Select((order, index) => (
                Column: $"{SqliteNames.Quote(table.Name)}.{SqliteNames.Quote(Resolve(entity, table, order.Field))}",
                order.Direction,
                Value: index < orders.Count - 1 ? position.SortValues[index] : position.Id.Raw()))
            .ToList();

        var branches = new List<string>();

        for (var index = 0; index < terms.Count; index++)
        {
            var parts = terms.Take(index).Select(term => Equal(term.Column, term.Value, bindings)).ToList();

            parts.Add(Beyond(terms[index].Column, terms[index].Direction, terms[index].Value, bindings));
            branches.Add("(" + string.Join(" AND ", parts) + ")");
        }

        return "(" + string.Join(" OR ", branches) + ")";
    }

    private static string Equal(string column, object? value, Bindings bindings) =>
        value is null ? $"{column} IS NULL" : $"{column} = {bindings.Add(Scalar(value))}";

    private static string Beyond(string column, Direction direction, object? value, Bindings bindings) => (direction, value) switch
    {
        (Direction.Ascending, null) => $"{column} IS NOT NULL",
        (Direction.Ascending, _) => $"{column} > {bindings.Add(Scalar(value))}",
        (_, null) => "1 = 0",
        _ => $"({column} < {bindings.Add(Scalar(value))} OR {column} IS NULL)",
    };

    /// <summary>
    /// The column a field is stored in. A field the manifest maps is taken as mapped; anything else is spelled the way a column
    /// is (<c>postId</c> is <c>post_id</c>). The table must have it either way.
    /// </summary>
    private string Resolve(string entity, SqliteTable table, string field)
    {
        var column = manifest.Columns.TryGetValue(entity, out var fields) && fields.TryGetValue(field, out var mapped)
            ? mapped
            : SqliteNames.Snake(field);

        return table.Column(column) is null
            ? throw new InvalidOperationException($"Table {table.Name} has no column for field \"{field}\".")
            : column;
    }

    private static object Scalar(object? value) => value switch
    {
        string or long or double => value,
        bool flag => flag ? 1L : 0L,
        int or short or sbyte or byte or ushort or uint => Convert.ToInt64(value, CultureInfo.InvariantCulture),
        float number => (double)number,
        _ => throw new InvalidOperationException("Filter values must be scalar."),
    };

    private static string EscapeLike(object? value) =>
        (value as string ?? throw new InvalidOperationException("Filter values must be scalar."))
            .Replace("\\", "\\\\", StringComparison.Ordinal)
            .Replace("%", "\\%", StringComparison.Ordinal)
            .Replace("_", "\\_", StringComparison.Ordinal);

    /// <summary>An id as the database holds it: the number or the text, not the wrapper.</summary>
    private static object Raw(IIdentifier identifier) =>
        identifier is EntityId id ? id.Raw() : identifier.ToString()!;

    private sealed record LinkSql(string Join, List<string> Clauses, string Projection);

    /// <summary>Collects parameter values, handing back the placeholder for each.</summary>
    private sealed class Bindings
    {
        private readonly Dictionary<string, object?> values = [];

        public IReadOnlyDictionary<string, object?> Values => values;

        public string Add(object? value)
        {
            var name = "p" + values.Count.ToString(CultureInfo.InvariantCulture);

            values[name] = value;

            return "@" + name;
        }
    }
}
