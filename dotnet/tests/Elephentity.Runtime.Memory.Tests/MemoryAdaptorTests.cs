using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Memory;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Testing;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Runtime.Memory.Tests;

public sealed class MemoryAdaptorTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public void ItDeclaresOnlyTransactions()
    {
        using var adaptor = new MemoryAdaptor();

        Assert.True(adaptor.Capabilities.Supports(Elephentity.Runtime.Capability.Capability.Transactions));
        Assert.Equal([Elephentity.Runtime.Capability.Capability.Transactions], adaptor.Capabilities.All());
        Assert.Equal("memory", adaptor.Capabilities.Adaptor);
    }

    [Fact]
    public async Task TwoEntitiesWithTheSameIdDoNotCollide()
    {
        // Ids come from one shared counter, but records are keyed by entity too: Post#1 and Comment#1 are
        // different rows. Running the suite for two entities against one adaptor proves they do not interfere.
        using var adaptor = new MemoryAdaptor();

        Assert.Empty(await AdaptorConformance.CheckAsync(() => adaptor, new ConformanceNames("Alpha", "related", "Beta"), Token));
        Assert.Empty(await AdaptorConformance.CheckAsync(() => adaptor, new ConformanceNames("Beta", "related", "Alpha"), Token));
    }

    [Fact]
    public async Task ABatchedEdgeQuerySaysWhichParentEachRowBelongsTo()
    {
        using var adaptor = new MemoryAdaptor();
        var (p1, p2) = (await Insert(adaptor, "Post"), await Insert(adaptor, "Post"));
        var (shared, own) = (await Insert(adaptor, "Comment"), await Insert(adaptor, "Comment"));
        await adaptor.WriteAsync(
            new WriteBatch(
                new Link("Post", "comments", p1, shared),
                new Link("Post", "comments", p2, shared),
                new Link("Post", "comments", p2, own)),
            Token);

        var forward = await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", p1, p2)), Token);
        Assert.Equal(
            [$"{shared}<-{p1}", $"{shared}<-{p2}", $"{own}<-{p2}"],
            Pairs(forward.Items).Order());

        var back = await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "comments", shared, own)), Token);
        Assert.Equal(
            [$"{p1}<-{shared}", $"{p2}<-{shared}", $"{p2}<-{own}"],
            Pairs(back.Items).Order());

        var single = await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", p2)), Token);
        Assert.Equal(2, single.Items.Count);
        Assert.False(single.Items[0].Has(EdgeFilter.ParentColumn), "one parent needs no grouping");
    }

    [Fact]
    public async Task AKeysetCursorResumesStrictlyAfterItsPositionAndAnOffsetStillWorks()
    {
        using var adaptor = new MemoryAdaptor();
        var ids = new List<EntityId>();

        foreach (var rank in new long[] { 2, 1, 2, 3, 1 })
        {
            ids.Add(await Insert(adaptor, "Item", new Dictionary<string, object?> { ["rank"] = rank }));
        }

        var criteria = Criteria.For("Item").OrderBy(Order.Ascending("rank"));
        var first = await adaptor.QueryAsync(criteria.Take(2), Token);

        // Rank 1: ids 2 and 5. The cursor names the second of them.
        Assert.Equal([ids[1], ids[4]], first.Items.Select(record => record.Id));
        Assert.IsType<OffsetCursor>(first.Next);

        var resumed = await adaptor.QueryAsync(criteria.Take(2, new KeysetCursor([1L], ids[4])), Token);

        Assert.Equal([ids[0], ids[2]], resumed.Items.Select(record => record.Id));
        Assert.IsType<KeysetCursor>(resumed.Next);

        var last = await adaptor.QueryAsync(criteria.Take(2, resumed.Next), Token);

        Assert.Equal([ids[3]], last.Items.Select(record => record.Id));
        Assert.Null(last.Next);
    }

    [Fact]
    public async Task AKeysetCursorForADifferentOrderIsRefused()
    {
        using var adaptor = new MemoryAdaptor();
        var cursor = new KeysetCursor([1L, 2L], EntityId.Of(1));

        await Assert.ThrowsAsync<ArgumentException>(() =>
            adaptor.QueryAsync(Criteria.For("Item").OrderBy(Order.Ascending("rank")).Take(2, cursor), Token));
    }

    [Fact]
    public async Task ARolledBackTransactionRestoresRowsLinksAndTheIdCounter()
    {
        using var adaptor = new MemoryAdaptor();
        var kept = await Insert(adaptor, "Post");
        var target = await Insert(adaptor, "Comment");

        await Assert.ThrowsAsync<InvalidOperationException>(() => adaptor.TransactionAsync<int>(
            async token =>
            {
                await adaptor.WriteAsync(new WriteBatch(new Link("Post", "comments", kept, target)), token);
                await adaptor.WriteAsync(new WriteBatch(new Delete("Post", kept)), token);
                await Insert(adaptor, "Post");

                throw new InvalidOperationException("no");
            },
            Token));

        Assert.NotNull(await adaptor.GetAsync("Post", kept, Token));
        Assert.Equal(1, await adaptor.CountAsync(Criteria.For("Post").Unbounded(), Token));
        Assert.Empty((await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", kept)), Token)).Items);
        Assert.Equal(EntityId.Of(3), await Insert(adaptor, "Post"));
    }

    [Fact]
    public async Task ParallelWritersGetDistinctIds()
    {
        using var adaptor = new MemoryAdaptor();

        var ids = await Task.WhenAll(Enumerable.Range(0, 200).Select(_ => Task.Run(() => Insert(adaptor, "Item"), Token)));

        Assert.Equal(200, ids.Distinct().Count());
        Assert.Equal(200, await adaptor.CountAsync(Criteria.For("Item").Unbounded(), Token));
    }

    [Fact]
    public async Task ATransactionOpenedInsideAnotherFailsInsteadOfWaitingForItself()
    {
        using var adaptor = new MemoryAdaptor();

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() => adaptor.TransactionAsync(
            token => adaptor.TransactionAsync(_ => Task.FromResult(1), token),
            Token));

        Assert.Contains("do not nest", failure.Message, StringComparison.Ordinal);

        // The lock was released: a transaction afterwards works.
        Assert.Equal(7, await adaptor.TransactionAsync(_ => Task.FromResult(7), Token));
    }

    [Fact]
    public async Task TransactionsDoNotInterleave()
    {
        using var adaptor = new MemoryAdaptor();
        var running = 0;
        var overlap = false;

        await Task.WhenAll(Enumerable.Range(0, 20).Select(_ => adaptor.TransactionAsync(
            async _ =>
            {
                overlap |= Interlocked.Increment(ref running) > 1;
                await Task.Yield();
                Interlocked.Decrement(ref running);

                return 0;
            },
            Token)));

        Assert.False(overlap);
    }

    [Fact]
    public async Task FiltersTreatTypesStrictlyAndOrderingNeverThrowsOnAMixedColumn()
    {
        using var adaptor = new MemoryAdaptor();
        await Insert(adaptor, "Item", new Dictionary<string, object?> { ["v"] = 1L });
        await Insert(adaptor, "Item", new Dictionary<string, object?> { ["v"] = "1" });
        await Insert(adaptor, "Item", new Dictionary<string, object?> { ["v"] = null });
        await Insert(adaptor, "Item", new Dictionary<string, object?> { ["v"] = 2.5 });

        var one = await adaptor.QueryAsync(Criteria.For("Item").Where(Filter.Equal("v", 1)), Token);
        var text = await adaptor.QueryAsync(Criteria.For("Item").Where(Filter.Equal("v", "1")), Token);
        var ordered = await adaptor.QueryAsync(Criteria.For("Item").OrderBy(Order.Ascending("v")), Token);

        Assert.Single(one.Items);
        Assert.Single(text.Items);
        Assert.Equal(4, ordered.Items.Count);
        Assert.Null(ordered.Items[0].Value("v"));
    }

    private static async Task<EntityId> Insert(MemoryAdaptor adaptor, string entity, Dictionary<string, object?>? values = null)
    {
        var pending = new PendingId(entity);
        var result = await adaptor.WriteAsync(new WriteBatch(new Insert(entity, pending, values ?? [])), TestContext.Current.CancellationToken);

        return result.IdFor(pending);
    }

    private static IEnumerable<string> Pairs(IEnumerable<Record> records) =>
        records.Select(record => $"{record.Id}<-{record.Value(EdgeFilter.ParentColumn)}");
}
