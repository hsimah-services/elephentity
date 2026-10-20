using System.Runtime.CompilerServices;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Storage;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;

namespace Elephentity.Runtime.Query;

/// <summary>
/// A criteria that has not run yet.
/// </summary>
/// <remarks>
/// <para>
/// Nothing reaches storage until one of these methods is called, which is what makes it safe for an entity to
/// hand one back from an edge accessor: asking a Post for its comments costs nothing until you ask the comments
/// something. <see cref="CountAsync" /> goes to the adaptor's count rather than hydrating and counting.
/// </para>
/// <para>
/// <b>Paging an entity with read policies resumes by keyset.</b> A database count and page include rows the
/// viewer cannot receive, so a gated entity is read a chunk at a time after the cursor, in the criteria's total
/// order (the sort keys, then the id); each chunk is hydrated and filtered, and reading stops when the page is
/// full and one more visible row proves there is a next page. The cursor handed back names the last visible row
/// by its sort values and id, so a hidden row leaves no trace in it, and the next page costs what it scans, not
/// everything before it. The PHP runtime re-walks every earlier row on every page; this does not. It depends on the
/// adaptor resuming strictly after a <see cref="KeysetCursor" /> (<see cref="IStorageAdaptor.QueryAsync" /> says so),
/// and fails with an exception naming the entity rather than looping when one does not. An ungated entity passes
/// the cursor straight to storage.
/// </para>
/// </remarks>
/// <typeparam name="T">The entity type.</typeparam>
public sealed partial class LazyEntityQuery<T> : IEntityQuery<T>
    where T : class
{
    /// <summary>Rows read per storage query while walking a gated set. Under every adaptor's page cap.</summary>
    private const int Chunk = 100;

    private readonly IStorageAdaptor storage;
    private readonly IHydrator<T> hydrator;
    private readonly IEdgeLoader edges;
    private readonly Criteria criteria;
    private readonly ReadGate gate;
    private readonly ILogger log;

    /// <summary>A query over <paramref name="criteria" />.</summary>
    /// <param name="storage">Where rows are read.</param>
    /// <param name="hydrator">Builds the entities.</param>
    /// <param name="edges">Resolves the entities' edges.</param>
    /// <param name="criteria">What to fetch.</param>
    /// <param name="gate">Enforces read policies.</param>
    /// <param name="log">Where a restarted page is logged.</param>
    public LazyEntityQuery(
        IStorageAdaptor storage,
        IHydrator<T> hydrator,
        IEdgeLoader edges,
        Criteria criteria,
        ReadGate gate,
        ILogger? log = null)
    {
        this.storage = storage;
        this.hydrator = hydrator;
        this.edges = edges;
        this.criteria = criteria;
        this.gate = gate;
        this.log = log ?? NullLogger.Instance;
    }

    /// <inheritdoc />
    public async ValueTask<long> CountAsync(CancellationToken cancellationToken = default)
    {
        // A storage count includes rows the viewer cannot receive, so a gated entity hydrates the set and
        // counts the survivors.
        if (!gate.Guards(criteria.Entity))
        {
            return await storage.CountAsync(criteria.Unbounded(), cancellationToken).ConfigureAwait(false);
        }

        var visible = 0L;

        await foreach (var _ in VisibleAsync(null, cancellationToken).ConfigureAwait(false))
        {
            visible++;
        }

        return visible;
    }

    /// <inheritdoc />
    public async ValueTask<Page<T>> PageAsync(int limit, ICursor? after = null, CancellationToken cancellationToken = default)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(limit);

        if (limit == 0)
        {
            return new Page<T>([]);
        }

        limit = Math.Min(limit, Page<T>.MaxLimit);

        if (!gate.Guards(criteria.Entity))
        {
            var page = await storage.QueryAsync(criteria.Take(limit, after), cancellationToken).ConfigureAwait(false);
            var items = await HydrateAsync(page.Items, cancellationToken).ConfigureAwait(false);

            return new Page<T>(items, page.Next);
        }

        var resume = KeysetCursor.From(after);

        if (after is not null && resume is null)
        {
            CursorRestarted(log, criteria.Entity);
        }

        var taken = new List<T>(limit);
        Record? last = null;

        await foreach (var (record, item) in VisibleAsync(resume, cancellationToken).ConfigureAwait(false))
        {
            if (taken.Count == limit)
            {
                // One more visible row exists, so there is a next page, and it starts after the last row taken.
                return new Page<T>(taken, KeysetAfter(last!));
            }

            taken.Add(item);
            last = record;
        }

        return new Page<T>(taken);
    }

    /// <inheritdoc />
    public async ValueTask<IReadOnlyList<T>> AllAsync(CancellationToken cancellationToken = default)
    {
        // Deliberately unbounded, and deliberately explicit: reaching for All is how you say the set is known to
        // be small.
        var page = await storage.QueryAsync(criteria.Unbounded(), cancellationToken).ConfigureAwait(false);

        return await HydrateAsync(page.Items, cancellationToken).ConfigureAwait(false);
    }

    /// <inheritdoc />
    public async ValueTask<T?> FirstAsync(CancellationToken cancellationToken = default)
    {
        var page = await PageAsync(1, null, cancellationToken).ConfigureAwait(false);

        return page.Items.Count > 0 ? page.Items[0] : null;
    }

    /// <inheritdoc />
    public async ValueTask<bool> ExistsAsync(CancellationToken cancellationToken = default) =>
        await CountAsync(cancellationToken).ConfigureAwait(false) > 0;

    /// <summary>
    /// Every row of the criteria the viewer may see after <paramref name="from" />, read a chunk at a time so
    /// memory holds one chunk rather than the set. Each chunk's cursor is built from its last row, never taken
    /// from the adaptor, so the walk does not depend on how an adaptor spells its own cursors.
    /// </summary>
    private async IAsyncEnumerable<(Record Record, T Item)> VisibleAsync(
        KeysetCursor? from,
        [EnumeratorCancellation] CancellationToken cancellationToken = default)
    {
        var cursor = from;

        while (true)
        {
            var page = await storage.QueryAsync(criteria.Take(Chunk, cursor), cancellationToken).ConfigureAwait(false);

            // Resuming strictly after a position means the row at that position is never in the answer. An
            // adaptor that cannot read a keyset cursor starts again, so the row is there, and this walk would
            // never end: a request that hangs instead of one that says why.
            if (cursor is not null && page.Items.Any(record => record.Id == cursor.Id))
            {
                throw new InvalidOperationException(
                    $"The storage adaptor did not resume {criteria.Entity} after the keyset cursor it was given "
                    + $"(it returned the row at {cursor.Id} again). A policy-gated entity is read by keyset, so the adaptor "
                    + "must return only rows strictly after (sort values, id); see IStorageAdaptor.QueryAsync.");
            }

            var objects = page.Items.Select(record => hydrator.Hydrate(record, edges)).ToList();
            var retained = await gate.RetainAsync(criteria.Entity, [.. objects], cancellationToken).ConfigureAwait(false);
            var visible = new HashSet<object>(retained, ReferenceEqualityComparer.Instance);

            for (var index = 0; index < objects.Count; index++)
            {
                if (visible.Contains(objects[index]))
                {
                    yield return (page.Items[index], objects[index]);
                }
            }

            if (page.Next is null || page.Items.Count == 0)
            {
                yield break;
            }

            cursor = KeysetAfter(page.Items[^1]);
        }
    }

    /// <summary>The position after <paramref name="record" /> in the criteria's total order.</summary>
    private KeysetCursor KeysetAfter(Record record)
    {
        // Every order term but the final id: the id travels as the cursor's own id. The order is the one
        // paging uses, with the id term appended when the criteria did not end in one.
        var orders = criteria.WithStableOrder().Orders;
        var sortValues = orders
            .Take(orders.Count - 1)
            .Select(order => record.Value(order.Field))
            .ToList();

        return new KeysetCursor(sortValues, record.Id);
    }

    private async ValueTask<IReadOnlyList<T>> HydrateAsync(IReadOnlyList<Record> records, CancellationToken cancellationToken)
    {
        var objects = records.Select(record => hydrator.Hydrate(record, edges)).ToList();
        var retained = await gate.RetainAsync(criteria.Entity, [.. objects], cancellationToken).ConfigureAwait(false);

        return [.. retained.Cast<T>()];
    }

    [LoggerMessage(
        EventId = 3,
        Level = LogLevel.Debug,
        Message = "A page cursor could not be read as a keyset position, so the list restarts. Entity={Entity}")]
    private static partial void CursorRestarted(ILogger log, string entity);
}
