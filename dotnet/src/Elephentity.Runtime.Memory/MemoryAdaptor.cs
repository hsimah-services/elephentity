using System.Globalization;
using Elephentity.Runtime.Capability;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;

namespace Elephentity.Runtime.Memory;

/// <summary>
/// Dictionaries over the storage port, with no backend of its own.
/// </summary>
/// <remarks>
/// <para>
/// The neutral driver: the cheapest honest second implementation of <see cref="IStorageAdaptor" />, which
/// proves the port is a real port rather than one adaptor's shape asserted to be general. It declares only
/// <see cref="Capability.Capability.Transactions" />, which is the honest answer for a store with no disk, no
/// network and no other process to disagree with it.
/// </para>
/// <para>
/// Edges are the one thing no schema tells this adaptor about, because it has no schema: a link is stored as a
/// fact (<em>"entity's edge points at to"</em>) keyed by entity and edge name alone, and a query asks the same
/// fact back rather than a join plan a manifest would otherwise supply.
/// </para>
/// <para>
/// <b>Threads.</b> Every operation takes one lock, so separate tests sharing nothing and a few threads sharing an
/// adaptor are both safe. A transaction snapshots on entry and restores on failure, and holds a second lock for
/// its duration so two transactions never interleave. Transactions do not nest: opening one inside another throws
/// rather than waiting for itself. A write made <em>outside</em> a transaction while one is
/// open is part of the state the snapshot restores, so share an adaptor between threads only if either nothing
/// writes during a transaction or losing that write on rollback is acceptable.
/// </para>
/// <para>
/// A <see cref="KeysetCursor" /> resumes strictly after its position in the criteria's total order, as
/// <see cref="IStorageAdaptor.QueryAsync" /> requires; any other cursor is read as an offset, as in PHP.
/// </para>
/// </remarks>
public sealed class MemoryAdaptor : IStorageAdaptor, IDisposable
{
    private readonly Lock gate = new();
    private readonly SemaphoreSlim transactions = new(1, 1);
    private readonly AsyncLocal<bool> inTransaction = new();

    private Dictionary<string, OrderedDictionary<string, Record>> records = [];
    private Dictionary<string, Dictionary<string, List<string>>> relations = [];
    private long nextId = 1;

    /// <summary>Releases the transaction lock.</summary>
    public void Dispose() => transactions.Dispose();

    /// <inheritdoc />
    public Capabilities Capabilities { get; } = new("memory", Capability.Capability.Transactions);

    /// <inheritdoc />
    public Task<Record?> GetAsync(string entity, EntityId id, CancellationToken cancellationToken = default)
    {
        lock (gate)
        {
            return Task.FromResult(Find(entity, id.ToString()));
        }
    }

    /// <inheritdoc />
    public Task<IReadOnlyList<Record>> GetManyAsync(
        string entity,
        IReadOnlyList<EntityId> ids,
        CancellationToken cancellationToken = default)
    {
        lock (gate)
        {
            IReadOnlyList<Record> found = [.. ids.Select(id => Find(entity, id.ToString())).OfType<Record>()];

            return Task.FromResult(found);
        }
    }

    /// <inheritdoc />
    public Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        if (criteria.Limit is < 0)
        {
            throw new ArgumentOutOfRangeException(nameof(criteria), "Page size must be non-negative.");
        }

        if (criteria.Limit == 0)
        {
            return Task.FromResult(new Page<Record>([]));
        }

        lock (gate)
        {
            var orders = criteria.WithStableOrder().Orders;
            var matches = Order(Matching(criteria), orders);

            if (criteria.Limit is not { } requested)
            {
                return Task.FromResult(new Page<Record>(matches));
            }

            var limit = Math.Min(requested, Page<Record>.MaxLimit);

            if (KeysetCursor.From(criteria.After) is { } position)
            {
                return Task.FromResult(Resume(matches, orders, position, limit));
            }

            var offset = (int)Math.Min(OffsetCursor.From(criteria.After).Value, matches.Count);

            // One row more than asked for: its presence is the answer to "is there another page", dropped
            // before anything above ever sees it.
            var window = matches.Skip(offset).Take(limit + 1).ToList();
            var hasMore = window.Count > limit;

            return Task.FromResult(new Page<Record>(
                [.. window.Take(limit)],
                hasMore ? new OffsetCursor(offset + limit) : null));
        }
    }

    /// <inheritdoc />
    public Task<long> CountAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        lock (gate)
        {
            return Task.FromResult((long)Matching(criteria).Count);
        }
    }

    /// <inheritdoc />
    public Task<WriteResult> WriteAsync(WriteBatch batch, CancellationToken cancellationToken = default)
    {
        lock (gate)
        {
            var result = new WriteResult();

            foreach (var operation in batch.Operations)
            {
                Apply(operation, result);
            }

            return Task.FromResult(result);
        }
    }

    /// <inheritdoc />
    public async Task<T> TransactionAsync<T>(Func<CancellationToken, Task<T>> work, CancellationToken cancellationToken = default)
    {
        // The lock is not reentrant, so a transaction opened inside another would wait for itself for ever.
        // Say so instead.
        if (inTransaction.Value)
        {
            throw new InvalidOperationException(
                "MemoryAdaptor transactions do not nest: TransactionAsync was called from inside another TransactionAsync on the same adaptor.");
        }

        await transactions.WaitAsync(cancellationToken).ConfigureAwait(false);
        inTransaction.Value = true;

        try
        {
            // No real transaction to open: a snapshot taken before the work runs and restored if it throws is
            // what makes "all of these writes or none of them" true for a store with no log of its own.
            var snapshot = Snapshot();

            try
            {
                return await work(cancellationToken).ConfigureAwait(false);
            }
            catch
            {
                Restore(snapshot);

                throw;
            }
        }
        finally
        {
            inTransaction.Value = false;
            transactions.Release();
        }
    }

    private (Dictionary<string, OrderedDictionary<string, Record>> Records, Dictionary<string, Dictionary<string, List<string>>> Relations, long NextId) Snapshot()
    {
        lock (gate)
        {
            return (
                records.ToDictionary(entity => entity.Key, entity => new OrderedDictionary<string, Record>(entity.Value)),
                relations.ToDictionary(
                    edge => edge.Key,
                    edge => edge.Value.ToDictionary(from => from.Key, from => new List<string>(from.Value))),
                nextId);
        }
    }

    private void Restore((Dictionary<string, OrderedDictionary<string, Record>> Records, Dictionary<string, Dictionary<string, List<string>>> Relations, long NextId) snapshot)
    {
        lock (gate)
        {
            (records, relations, nextId) = snapshot;
        }
    }

    private Record? Find(string entity, string id) =>
        records.TryGetValue(entity, out var rows) && rows.TryGetValue(id, out var record) ? record : null;

    private void Apply(IWriteOperation operation, WriteResult result)
    {
        switch (operation)
        {
            case Insert insert:
                var id = EntityId.Of(nextId++);
                Rows(insert.Entity)[id.ToString()] = new Record(insert.Entity, id, new Dictionary<string, object?>(insert.Values));
                result.Assign(insert.PendingId, id);
                break;
            case Update update:
                var key = update.Id.ToString();
                var existing = Find(update.Entity, key)
                    ?? throw new InvalidOperationException($"Cannot update {update.Entity}#{key}: no such row.");
                var merged = new Dictionary<string, object?>(existing.Values);

                foreach (var (field, value) in update.Values)
                {
                    merged[field] = value;
                }

                Rows(update.Entity)[key] = new Record(update.Entity, existing.Id, merged);
                break;
            case Delete delete:
                Rows(delete.Entity).Remove(delete.Id.ToString());
                break;
            case Link link:
                var targets = Targets($"{link.Entity}.{link.Edge}", link.From.ToString()!);
                var to = link.To.ToString()!;

                if (!targets.Contains(to))
                {
                    targets.Add(to);
                }

                break;
            case Unlink unlink:
                var edge = $"{unlink.Entity}.{unlink.Edge}";
                var from = unlink.From.ToString()!;

                if (unlink.To is null)
                {
                    if (relations.TryGetValue(edge, out var all))
                    {
                        all.Remove(from);
                    }
                }
                else if (relations.TryGetValue(edge, out var adjacency) && adjacency.TryGetValue(from, out var linked))
                {
                    linked.Remove(unlink.To.ToString()!);
                }

                break;
            default:
                throw new InvalidOperationException($"Unknown write operation {operation.GetType().Name}.");
        }
    }

    private OrderedDictionary<string, Record> Rows(string entity)
    {
        if (!records.TryGetValue(entity, out var rows))
        {
            records[entity] = rows = [];
        }

        return rows;
    }

    private List<string> Targets(string edge, string from)
    {
        if (!relations.TryGetValue(edge, out var adjacency))
        {
            relations[edge] = adjacency = [];
        }

        if (!adjacency.TryGetValue(from, out var targets))
        {
            adjacency[from] = targets = [];
        }

        return targets;
    }

    private List<Record> Matching(Criteria criteria)
    {
        var matched = records.TryGetValue(criteria.Entity, out var rows) ? rows.Values.ToList() : [];

        foreach (var filter in criteria.Filters)
        {
            matched = [.. matched.Where(record => Matches(record, filter))];
        }

        foreach (var link in criteria.Links)
        {
            matched = Linked(matched, link);
        }

        return matched;
    }

    private static bool Matches(Record record, Filter filter)
    {
        var value = record.Value(filter.Field);

        return filter.Comparison switch
        {
            Comparison.Equal => Values.AreEqual(value, filter.Value),
            Comparison.NotEqual => !Values.AreEqual(value, filter.Value),
            Comparison.LessThan => value is not null && Values.Compare(value, filter.Value) < 0,
            Comparison.LessThanOrEqual => value is not null && Values.Compare(value, filter.Value) <= 0,
            Comparison.GreaterThan => value is not null && Values.Compare(value, filter.Value) > 0,
            Comparison.GreaterThanOrEqual => value is not null && Values.Compare(value, filter.Value) >= 0,
            Comparison.In => filter.Value is IReadOnlyList<object> list && list.Any(item => Values.AreEqual(value, item)),
            Comparison.NotIn => filter.Value is not IReadOnlyList<object> list || !list.Any(item => Values.AreEqual(value, item)),
            Comparison.Contains => value is string text && filter.Value is string needle && text.Contains(needle, StringComparison.Ordinal),
            Comparison.StartsWith => value is string text && filter.Value is string prefix && text.StartsWith(prefix, StringComparison.Ordinal),
            Comparison.IsNull => value is null,
            Comparison.IsNotNull => value is not null,
            _ => throw new InvalidOperationException($"Unknown comparison {filter.Comparison}."),
        };
    }

    private List<Record> Linked(List<Record> matched, EdgeFilter link)
    {
        relations.TryGetValue($"{link.Entity}.{link.Edge}", out var adjacency);
        var parents = link.From.Select(parent => parent.ToString()!).ToList();
        var result = new List<Record>();

        foreach (var record in matched)
        {
            var id = record.Id.ToString();

            foreach (var parent in parents)
            {
                // Reversed, the record declares the edge and the parent is its target; forwards, the parent
                // declares it and the record is what it points at.
                var linked = link.Reversed
                    ? adjacency is not null && adjacency.TryGetValue(id, out var forward) && forward.Contains(parent)
                    : adjacency is not null && adjacency.TryGetValue(parent, out var backward) && backward.Contains(id);

                if (!linked)
                {
                    continue;
                }

                if (!link.NeedsParentColumn())
                {
                    result.Add(record);

                    break;
                }

                // One row per parent, as a join would return it, so a batch can group them.
                result.Add(new Record(
                    record.Entity,
                    record.Id,
                    new Dictionary<string, object?>(record.Values) { [EdgeFilter.ParentColumn] = parent }));
            }
        }

        return result;
    }

    private static List<Record> Order(List<Record> matched, IReadOnlyList<Order> orders) =>
        orders.Count == 0 ? matched : [.. matched.Order(new Ordering(orders))];

    /// <summary>The rows strictly after <paramref name="position" /> in the total order, as one page.</summary>
    private static Page<Record> Resume(List<Record> matches, IReadOnlyList<Order> orders, KeysetCursor position, int limit)
    {
        if (position.SortValues.Count != orders.Count - 1)
        {
            throw new ArgumentException(
                $"The keyset cursor carries {position.SortValues.Count} sort value(s) but the criteria order by {orders.Count - 1}.",
                nameof(position));
        }

        var ordering = new Ordering(orders);
        var after = matches.Where(record => ordering.CompareToPosition(record, position) > 0).Take(limit + 1).ToList();
        var page = after.Take(limit).ToList();
        ICursor? next = after.Count > limit
            ? new KeysetCursor([.. orders.Take(orders.Count - 1).Select(order => page[^1].Value(order.Field))], page[^1].Id)
            : null;

        return new Page<Record>(page, next);
    }

    /// <summary>The total order of a criteria: its terms in turn, then the batch parent.</summary>
    private sealed class Ordering(IReadOnlyList<Order> orders) : IComparer<Record>
    {
        public int Compare(Record? left, Record? right)
        {
            foreach (var order in orders)
            {
                var comparison = Values.Compare(ValueOf(left!, order.Field), ValueOf(right!, order.Field));

                if (comparison != 0)
                {
                    return order.Direction == Direction.Descending ? -comparison : comparison;
                }
            }

            return Values.Compare(left!.Value(EdgeFilter.ParentColumn), right!.Value(EdgeFilter.ParentColumn));
        }

        /// <summary>How <paramref name="record" /> sorts against a position: positive when it comes after it.</summary>
        public int CompareToPosition(Record record, KeysetCursor position)
        {
            for (var index = 0; index < orders.Count - 1; index++)
            {
                var comparison = Values.Compare(ValueOf(record, orders[index].Field), position.SortValues[index]);

                if (comparison != 0)
                {
                    return orders[index].Direction == Direction.Descending ? -comparison : comparison;
                }
            }

            var last = orders[^1];
            var byId = Values.Compare(record.Id.Raw(), position.Id.Raw());

            return last.Direction == Direction.Descending ? -byId : byId;
        }

        private static object? ValueOf(Record record, string field) => field == "id" ? record.Id.Raw() : record.Value(field);
    }

    /// <summary>
    /// How stored values compare. Strict about type for equality (the integer 1 is not the text "1"), as PHP's
    /// <c>===</c> is, and total for ordering: nothing sorts before a missing value, numbers sort as numbers, and
    /// values of different kinds sort by kind, so an order never throws on a mixed column.
    /// </summary>
    private static class Values
    {
        public static bool AreEqual(object? left, object? right) =>
            (left, right) switch
            {
                (null, null) => true,
                (null, _) or (_, null) => false,
                _ when IsInteger(left) && IsInteger(right) => ToLong(left) == ToLong(right),
                _ when IsNumber(left) && IsNumber(right) && !IsInteger(left) && !IsInteger(right) => ToDouble(left) == ToDouble(right),
                (string a, string b) => string.Equals(a, b, StringComparison.Ordinal),
                (bool a, bool b) => a == b,
                _ => false,
            };

        public static int Compare(object? left, object? right)
        {
            if (left is null || right is null)
            {
                return (left is null ? 0 : 1) - (right is null ? 0 : 1);
            }

            if (IsInteger(left) && IsInteger(right))
            {
                return ToLong(left).CompareTo(ToLong(right));
            }

            if (IsNumber(left) && IsNumber(right))
            {
                return ToDouble(left).CompareTo(ToDouble(right));
            }

            if (left is string a && right is string b)
            {
                return Math.Sign(string.CompareOrdinal(a, b));
            }

            if (left is bool x && right is bool y)
            {
                return x.CompareTo(y);
            }

            return Rank(left).CompareTo(Rank(right));
        }

        private static int Rank(object value) => IsNumber(value) ? 0 : value is string ? 1 : 2;

        private static bool IsInteger(object? value) => value is long or int or short or sbyte or byte or ushort or uint;

        private static bool IsNumber(object? value) => IsInteger(value) || value is double or float or decimal;

        private static long ToLong(object value) => Convert.ToInt64(value, CultureInfo.InvariantCulture);

        private static double ToDouble(object value) => Convert.ToDouble(value, CultureInfo.InvariantCulture);
    }
}
