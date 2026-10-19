using Elephentity.Runtime.Capability;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Memory;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Testing;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Runtime.Testing.Tests;

/// <summary>
/// The suite has to be able to fail. Each adaptor here is the memory driver with exactly one promise broken,
/// and the case named for that promise (and only that case) must say so.
/// </summary>
public sealed class AdaptorConformanceTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public void CaseNamesAreUniqueAndMatchTheCases()
    {
        var names = AdaptorConformance.Cases().Select(conformance => conformance.Name).ToList();

        Assert.Equal(names, AdaptorConformance.CaseNames);
        Assert.Equal(names.Count, names.Distinct().Count());
        Assert.True(names.Count >= 20);
    }

    [Fact]
    public async Task TheMemoryDriverConformsToEveryCase()
    {
        Assert.Empty(await AdaptorConformance.CheckAsync(() => new MemoryAdaptor(), cancellationToken: Token));
    }

    [Fact]
    public async Task AnAdaptorThatDropsUpdatesFailsTheUpdateCase()
    {
        await AssertOnlyFails(() => new Saboteur { DropUpdates = true }, "crud.update-is-visible-to-a-later-get");
    }

    [Fact]
    public async Task AnAdaptorThatCannotDeleteFailsTheDeleteCase()
    {
        await AssertOnlyFails(() => new Saboteur { DropDeletes = true }, "crud.delete-removes-the-row");
    }

    [Fact]
    public async Task AnAdaptorThatAcceptsANegativePageSizeFailsThatCase()
    {
        await AssertOnlyFails(() => new Saboteur { AcceptNegativeLimit = true }, "paging.take-negative-is-rejected");
    }

    [Fact]
    public async Task AnAdaptorThatIgnoresKeysetCursorsFailsTheKeysetCase()
    {
        await AssertOnlyFails(() => new Saboteur { IgnoreKeyset = true }, "paging.keyset-cursor-resumes-strictly-after-its-position");
    }

    [Fact]
    public async Task AnAdaptorThatClearsEveryLinkOnATargetedUnlinkFailsThatCase()
    {
        await AssertOnlyFails(() => new Saboteur { TargetedUnlinkClearsAll = true }, "edges.unlinking-a-different-target-keeps-the-link");
    }

    [Fact]
    public async Task AnAdaptorWhoseTransactionsDoNotRollBackFailsTheRollbackCase()
    {
        await AssertOnlyFails(() => new Saboteur { NeverRollBack = true }, "transactions.roll-back-and-rethrow-when-the-work-throws");
    }

    [Fact]
    public async Task AnAdaptorThatSwallowsAFailingTransactionFailsTheRollbackCase()
    {
        await AssertOnlyFails(() => new Saboteur { SwallowFailure = true }, "transactions.roll-back-and-rethrow-when-the-work-throws");
    }

    [Fact]
    public async Task AnAdaptorThatLeavesOutTheParentColumnFailsTheBatchedCase()
    {
        await AssertOnlyFails(() => new Saboteur { DropParentColumn = true }, "edges.batched-queries-project-one-id-parent-pair-per-link");
    }

    [Fact]
    public async Task AFailureNamesTheCaseAndEveryProblemItFound()
    {
        var failing = AdaptorConformance.Cases().Single(conformance => conformance.Name == "crud.update-is-visible-to-a-later-get");

        var failure = await Assert.ThrowsAsync<ConformanceFailureException>(() => failing.Run(new Saboteur { DropUpdates = true }, Token));

        Assert.Equal("crud.update-is-visible-to-a-later-get", failure.CaseName);
        Assert.Contains("A written Update was not visible to a later GetAsync.", failure.Failures);
        Assert.StartsWith("crud.update-is-visible-to-a-later-get: ", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task CasesLeaveNothingBehindSoTheyCanRunInSequenceAgainstOneAdaptor()
    {
        using var adaptor = new MemoryAdaptor();

        foreach (var conformance in AdaptorConformance.Cases())
        {
            await conformance.Run(adaptor, Token);
        }

        var names = ConformanceNames.Default;

        Assert.Equal(0, await adaptor.CountAsync(Criteria.For(names.Entity).Unbounded(), Token));
        Assert.Equal(0, await adaptor.CountAsync(Criteria.For(names.Related).Unbounded(), Token));
    }

    private static async Task AssertOnlyFails(Func<IStorageAdaptor> create, string expected)
    {
        var failures = await AdaptorConformance.CheckAsync(create, cancellationToken: Token);

        Assert.Single(failures);
        Assert.StartsWith(expected + ": ", failures[0], StringComparison.Ordinal);
    }

    /// <summary>The memory driver with promises broken on request.</summary>
    private sealed class Saboteur : IStorageAdaptor, IDisposable
    {
        private readonly MemoryAdaptor inner = new();

        public void Dispose() => inner.Dispose();

        public bool DropUpdates { get; init; }

        public bool DropDeletes { get; init; }

        public bool AcceptNegativeLimit { get; init; }

        public bool IgnoreKeyset { get; init; }

        public bool TargetedUnlinkClearsAll { get; init; }

        public bool NeverRollBack { get; init; }

        public bool SwallowFailure { get; init; }

        public bool DropParentColumn { get; init; }

        public Capabilities Capabilities => inner.Capabilities;

        public Task<Record?> GetAsync(string entity, EntityId id, CancellationToken cancellationToken = default) =>
            inner.GetAsync(entity, id, cancellationToken);

        public Task<IReadOnlyList<Record>> GetManyAsync(string entity, IReadOnlyList<EntityId> ids, CancellationToken cancellationToken = default) =>
            inner.GetManyAsync(entity, ids, cancellationToken);

        public async Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default)
        {
            if (AcceptNegativeLimit && criteria.Limit is < 0)
            {
                return new Page<Record>([]);
            }

            if (IgnoreKeyset && KeysetCursor.From(criteria.After) is not null)
            {
                criteria = criteria.Take(criteria.Limit ?? Page<Record>.MaxLimit);
            }

            var page = await inner.QueryAsync(criteria, cancellationToken);

            if (!DropParentColumn)
            {
                return page;
            }

            return new Page<Record>(
                [.. page.Items.Select(record => new Record(
                    record.Entity,
                    record.Id,
                    record.Values.Where(value => value.Key != EdgeFilter.ParentColumn).ToDictionary(value => value.Key, value => value.Value)))],
                page.Next);
        }

        public Task<long> CountAsync(Criteria criteria, CancellationToken cancellationToken = default) =>
            inner.CountAsync(criteria, cancellationToken);

        public Task<WriteResult> WriteAsync(WriteBatch batch, CancellationToken cancellationToken = default) =>
            inner.WriteAsync(
                new WriteBatch([.. batch.Operations.Select(Break).OfType<IWriteOperation>()]),
                cancellationToken);

        public async Task<T> TransactionAsync<T>(Func<CancellationToken, Task<T>> work, CancellationToken cancellationToken = default)
        {
            if (NeverRollBack)
            {
                return await work(cancellationToken);
            }

            if (SwallowFailure)
            {
                try
                {
                    return await inner.TransactionAsync(work, cancellationToken);
                }
                catch (InvalidOperationException)
                {
                    return default!;
                }
            }

            return await inner.TransactionAsync(work, cancellationToken);
        }

        private IWriteOperation? Break(IWriteOperation operation) => operation switch
        {
            Update when DropUpdates => null,
            Delete when DropDeletes => null,
            Unlink { To: not null } unlink when TargetedUnlinkClearsAll => new Unlink(unlink.Entity, unlink.Edge, unlink.From),
            _ => operation,
        };
    }
}
