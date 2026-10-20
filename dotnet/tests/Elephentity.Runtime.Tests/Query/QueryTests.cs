using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Tests.Policy;
using Elephentity.Runtime.Tests.UnitOfWork;
using Microsoft.Extensions.Logging;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Runtime.Tests.Query;

public sealed class QueryTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task LazyQueryFiltersAndCountsSurvivors()
    {
        var (query, _) = Gated(rows: 10, hide: n => n % 2 == 0);

        Assert.Equal(5, await query.CountAsync(Token));
        Assert.True(await query.ExistsAsync(Token));
    }

    [Fact]
    public async Task AGatedPageCountsOnlyVisibleRows()
    {
        var (query, _) = Gated(rows: 10, hide: n => n % 2 == 0);

        var page = await query.PageAsync(3, null, Token);

        Assert.Equal([1L, 3L, 5L], page.Items.Select(N));
        Assert.True(page.HasMore);
    }

    [Fact]
    public async Task EveryVisibleRowIsReachedExactlyOnceByFollowingCursors()
    {
        var (query, _) = Gated(rows: 50, hide: n => n % 3 == 0);
        var seen = new List<long>();
        ICursor? cursor = null;

        do
        {
            var page = await query.PageAsync(7, cursor, Token);
            seen.AddRange(page.Items.Select(N));
            cursor = page.Next;
        }
        while (cursor is not null);

        Assert.Equal(Enumerable.Range(1, 50).Where(n => n % 3 != 0).Select(n => (long)n), seen);
    }

    [Fact]
    public async Task ADeepGatedPageScansOnlyWhatItNeeds()
    {
        var (query, storage) = Gated(rows: 1000, hide: n => n % 2 == 0);
        var first = await query.PageAsync(10, null, Token);
        storage.Scanned = 0;

        var second = await query.PageAsync(10, first.Next, Token);

        Assert.Equal(21L, N(second.Items[0]));
        Assert.True(storage.Scanned <= 200, $"scanned {storage.Scanned} rows for a page of 10");
    }

    [Fact]
    public async Task ACursorNamesTheLastVisibleRowNotAHiddenOne()
    {
        var (query, _) = Gated(rows: 10, hide: n => n == 4);

        var page = await query.PageAsync(3, null, Token);
        var cursor = KeysetCursor.From(page.Next);

        Assert.Equal(EntityId.Of(3), cursor!.Id);
    }

    [Fact]
    public async Task WhenEveryRowIsHiddenThePageIsEmptyAfterOneWalkOfTheSet()
    {
        var (query, storage) = Gated(rows: 250, hide: _ => true);

        var page = await query.PageAsync(10, null, Token);

        Assert.True(page.IsEmpty);
        Assert.Null(page.Next);
        Assert.Equal(250, storage.Scanned);
    }

    [Fact]
    public async Task CursorsFollowedAcrossChunkBoundariesReachEveryVisibleRowOnceInOrder()
    {
        // Chunk is 100, so rows 100, 200, 300 and 400 are each the last row of a chunk, and they are hidden,
        // as are the rows either side of the first boundary. Seven does not divide 100.
        bool Hidden(long n) => n % 100 == 0 || n is 99 or 101 or 201;
        var (query, _) = Gated(rows: 450, hide: Hidden);
        var seen = new List<long>();
        ICursor? cursor = null;
        var pages = 0;

        do
        {
            var page = await query.PageAsync(7, cursor, Token);
            seen.AddRange(page.Items.Select(N));
            cursor = page.Next;
            pages++;
        }
        while (cursor is not null && pages < 1000);

        Assert.Equal(Enumerable.Range(1, 450).Select(n => (long)n).Where(n => !Hidden(n)), seen);
    }

    [Fact]
    public async Task AnAdaptorThatIgnoresKeysetCursorsFailsInsteadOfSpinning()
    {
        // Every row is hidden, so the walk has to ask for a second chunk, which an adaptor that starts again
        // answers with the first one.
        var storage = new IgnoringCursorStorage();
        var catalogue = new StubCatalogue().Reading("Post", new StubPolicies((_, _) => PolicyDecision.Deny("hidden")));
        var queries = new Queries(storage, new NoEdges(), new ReadGate(catalogue, new AnonymousViewerProvider()));
        var query = queries.Of(new RecordHydrator(), Criteria.For("Post").OrderBy(Order.Ascending("n")));

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(async () => await query.PageAsync(5, null, Token));

        Assert.Contains("Post", failure.Message, StringComparison.Ordinal);
        Assert.Contains("keyset cursor", failure.Message, StringComparison.Ordinal);
        Assert.True(storage.Calls <= 3, $"the walk made {storage.Calls} calls before giving up");
    }

    [Fact]
    public async Task FirstFindsAReadableRowBehindADeniedOne()
    {
        var (query, _) = Gated(rows: 5, hide: n => n < 3);

        Assert.Equal(3L, N((await query.FirstAsync(Token))!));
    }

    [Fact]
    public async Task ZeroPageDoesNotReadStorage()
    {
        var (query, storage) = Gated(rows: 5, hide: _ => false);

        var page = await query.PageAsync(0, null, Token);

        Assert.True(page.IsEmpty);
        Assert.False(page.HasMore);
        Assert.Equal(0, storage.Scanned);
        await Assert.ThrowsAsync<ArgumentOutOfRangeException>(async () => await query.PageAsync(-1, null, Token));
    }

    [Fact]
    public async Task AnUnreadableCursorRestartsTheListAndIsLogged()
    {
        var log = new CapturingLogger();
        var (query, _) = Gated(rows: 5, hide: _ => false, log: log);

        var page = await query.PageAsync(2, new OffsetCursor(3), Token);

        Assert.Equal([1L, 2L], page.Items.Select(N));
        Assert.Contains(log.Entries, entry => entry.Message.Contains("restarts", StringComparison.Ordinal));
    }

    [Fact]
    public async Task AnUngatedEntityPassesTheCursorToStorage()
    {
        var storage = new FakeStorage();
        storage.Records["Post"] = [new Record("Post", EntityId.Of(1), new Dictionary<string, object?>())];
        var queries = new Queries(storage, new NoEdges(), new ReadGate(new StubCatalogue(), new AnonymousViewerProvider()));
        var query = queries.Of(new RecordHydrator(), Criteria.For("Post"));
        var cursor = new OffsetCursor(4);

        await query.PageAsync(5, cursor, Token);

        Assert.Same(cursor, storage.Queried[0].After);
    }

    [Fact]
    public async Task PreloadFiltersEachParentGroupAndIssuesOneQuery()
    {
        var storage = new FakeStorage();
        storage.Records["Comment"] =
        [
            new Record("Comment", EntityId.Of(10), new Dictionary<string, object?> { [EdgeFilter.ParentColumn] = 1L }),
            new Record("Comment", EntityId.Of(11), new Dictionary<string, object?> { [EdgeFilter.ParentColumn] = 1L }),
            new Record("Comment", EntityId.Of(12), new Dictionary<string, object?> { [EdgeFilter.ParentColumn] = 2L }),
        ];
        var catalogue = new StubCatalogue().Reading("Comment", new StubPolicies((entity, _) =>
            ((Record)entity).Id == EntityId.Of(11) ? PolicyDecision.Deny("no") : PolicyDecision.Allow()));
        var loader = new CachingEdgeLoader(
            storage,
            new Hydrators(),
            new Dictionary<string, string> { ["Post.comments"] = "Comment" },
            new ReadGate(catalogue, new AnonymousViewerProvider()));

        var grouped = await loader.PreloadAsync("Post", [EntityId.Of(1), EntityId.Of(2), EntityId.Of(3)], "comments", Token);

        Assert.Single(storage.Queried);
        Assert.Equal([EntityId.Of(10)], grouped["1"].Select(Id));
        Assert.Equal([EntityId.Of(12)], grouped["2"].Select(Id));
        Assert.Empty(grouped["3"]);
    }

    private static EntityId Id(object entity) => ((Record)entity).Id;

    private static long N(object entity) => Convert.ToInt64(((Record)entity).Value("n"), System.Globalization.CultureInfo.InvariantCulture);

    private static (IEntityQuery<object> Query, SortedStorage Storage) Gated(int rows, Func<long, bool> hide, ILogger? log = null)
    {
        var storage = new SortedStorage(rows);
        var catalogue = new StubCatalogue().Reading("Post", new StubPolicies((entity, _) =>
            hide(N(entity)) ? PolicyDecision.Deny("hidden") : PolicyDecision.Allow()));
        var queries = new Queries(storage, new NoEdges(), new ReadGate(catalogue, new AnonymousViewerProvider()), log);

        return (queries.Of(new RecordHydrator(), Criteria.For("Post").OrderBy(Order.Ascending("n"))), storage);
    }

    private sealed class RecordHydrator : IHydrator<object>
    {
        public object Hydrate(Record record, IEdgeLoader edges) => record;
    }

    private sealed class Hydrators : IHydratorRegistry
    {
        public bool Has(string entity) => true;

        public IHydrator<object> HydratorFor(string entity) => new RecordHydrator();
    }

    private sealed class NoEdges : IEdgeLoader
    {
        public IEntityQuery<object> ToMany(string entity, EntityId id, string edge) => throw new NotSupportedException();

        public ValueTask<object?> ToOneAsync(string entity, EntityId id, string edge, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public IEntityQuery<object> InverseToMany(string entity, string edge, EntityId id) => throw new NotSupportedException();

        public ValueTask<object?> InverseToOneAsync(string entity, string edge, EntityId id, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }
}

/// <summary>Posts 1..N with n = id, answering keyset cursors in (n, id) order.</summary>
internal sealed class SortedStorage(int rows) : FakeStorage
{
    public int Scanned { get; set; }

    public override Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        var after = KeysetCursor.From(criteria.After);
        var limit = criteria.Limit ?? int.MaxValue;
        var matching = Enumerable.Range(1, rows)
            .Where(n => after is null || n > Convert.ToInt64(after.SortValues[0], System.Globalization.CultureInfo.InvariantCulture))
            .Select(n => new Record("Post", EntityId.Of(n), new Dictionary<string, object?> { ["n"] = (long)n }))
            .ToList();
        var page = matching.Take(limit).ToList();
        Scanned += page.Count;
        ICursor? next = matching.Count > page.Count && page.Count > 0
            ? new KeysetCursor([page[^1].Value("n")], page[^1].Id)
            : null;

        return Task.FromResult(new Page<Record>(page, next));
    }
}

/// <summary>An adaptor that cannot read a keyset cursor and starts again, as PHP's offset reading does.</summary>
internal sealed class IgnoringCursorStorage : FakeStorage
{
    public int Calls { get; private set; }

    public override Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        Calls++;
        var page = Enumerable.Range(1, criteria.Limit ?? 100)
            .Select(n => new Record("Post", EntityId.Of(n), new Dictionary<string, object?> { ["n"] = (long)n }))
            .ToList();

        return Task.FromResult(new Page<Record>(page, new KeysetCursor([page[^1].Value("n")], page[^1].Id)));
    }
}
