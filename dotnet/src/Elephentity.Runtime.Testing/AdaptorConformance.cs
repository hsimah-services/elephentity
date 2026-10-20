using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;

namespace Elephentity.Runtime.Testing;

/// <summary>
/// The assertions any <see cref="IStorageAdaptor" /> must satisfy.
/// </summary>
/// <remarks>
/// <para>
/// The storage port was narrow and defined before a second implementation existed; this is what proves a second
/// implementation honours the same contract rather than merely type-checking against it. It is the C# port of the
/// PHP <c>AdaptorConformance</c>, including its page-size, stable-order, targeted-unlink and batched-edge cases
/// (elephentity#110), split into named cases so a failure says which promise broke. It adds the one promise PHP
/// has no use for: resuming strictly after a <see cref="KeysetCursor" />, which reading a policy-gated entity relies on.
/// </para>
/// <para>
/// Each case reports <i>every</i> problem it finds, not the first.
/// </para>
/// </remarks>
public static class AdaptorConformance
{
    private static readonly string[] Names =
    [
        "crud.insert-is-assigned-an-id-and-reads-back",
        "crud.get-of-an-id-nothing-wrote-is-null",
        "crud.get-many-returns-exactly-the-rows-that-exist",
        "crud.update-is-visible-to-a-later-get",
        "crud.delete-removes-the-row",
        "query.equals-filter-narrows-to-the-matching-row",
        "query.order-ascending",
        "query.order-descending",
        "query.count-matches-without-a-limit",
        "query.count-honours-a-filter",
        "paging.second-page-carries-the-remaining-row",
        "paging.take-zero-is-an-empty-terminal-page",
        "paging.take-negative-is-rejected",
        "paging.ties-and-no-order-appear-once-in-id-order",
        "paging.oversized-pages-cap-with-an-effective-size-cursor",
        "paging.keyset-cursor-resumes-strictly-after-its-position",
        "edges.a-link-is-visible-from-both-sides",
        "edges.unlinking-a-different-target-keeps-the-link",
        "edges.unlinking-the-target-removes-the-link",
        "edges.clear-all-unlink-removes-every-link-from-the-owner",
        "edges.batched-queries-project-one-id-parent-pair-per-link",
        "transactions.return-the-work-result",
        "transactions.roll-back-and-rethrow-when-the-work-throws",
    ];

    /// <summary>The name of every case, independent of any adaptor, for a framework to enumerate.</summary>
    public static IReadOnlyList<string> CaseNames => Names;

    /// <summary>Every case, writing to the entities and edge <paramref name="names" /> says.</summary>
    /// <param name="names">Where the cases write; <see cref="ConformanceNames.Default" /> when omitted.</param>
    /// <returns>The cases, each independent of the others.</returns>
    public static IEnumerable<ConformanceCase> Cases(ConformanceNames? names = null)
    {
        names ??= ConformanceNames.Default;

        return Names.Select(name => new ConformanceCase(
            name,
            async (adaptor, cancellationToken) =>
            {
                var problems = new Problems();

                await Run(name, new Context(adaptor, names, problems, cancellationToken)).ConfigureAwait(false);

                if (problems.Any)
                {
                    throw new ConformanceFailureException(name, problems.All);
                }
            }));
    }

    /// <summary>
    /// Run every case against a fresh adaptor each, and collect what failed.
    /// </summary>
    /// <param name="create">Makes a new, empty adaptor.</param>
    /// <param name="names">Where the cases write.</param>
    /// <param name="cancellationToken">Cancels the run.</param>
    /// <returns>One line per failure; empty when the adaptor conforms.</returns>
    public static async Task<IReadOnlyList<string>> CheckAsync(
        Func<IStorageAdaptor> create,
        ConformanceNames? names = null,
        CancellationToken cancellationToken = default)
    {
        var failures = new List<string>();

        foreach (var conformance in Cases(names))
        {
            try
            {
                await conformance.Run(create(), cancellationToken).ConfigureAwait(false);
            }
            catch (ConformanceFailureException failure)
            {
                failures.Add(failure.Message);
            }
        }

        return failures;
    }

    private static Task Run(string name, Context context) => name switch
    {
        "crud.insert-is-assigned-an-id-and-reads-back" => InsertReadsBack(context),
        "crud.get-of-an-id-nothing-wrote-is-null" => GetOfMissing(context),
        "crud.get-many-returns-exactly-the-rows-that-exist" => GetManyReturnsExisting(context),
        "crud.update-is-visible-to-a-later-get" => UpdateIsVisible(context),
        "crud.delete-removes-the-row" => DeleteRemoves(context),
        "query.equals-filter-narrows-to-the-matching-row" => EqualsFilter(context),
        "query.order-ascending" => Ordering(context, Direction.Ascending),
        "query.order-descending" => Ordering(context, Direction.Descending),
        "query.count-matches-without-a-limit" => CountTotal(context),
        "query.count-honours-a-filter" => CountFiltered(context),
        "paging.second-page-carries-the-remaining-row" => SecondPage(context),
        "paging.take-zero-is-an-empty-terminal-page" => TakeZero(context),
        "paging.take-negative-is-rejected" => TakeNegative(context),
        "paging.ties-and-no-order-appear-once-in-id-order" => TiesAndNoOrder(context),
        "paging.oversized-pages-cap-with-an-effective-size-cursor" => OversizedPages(context),
        "paging.keyset-cursor-resumes-strictly-after-its-position" => KeysetResume(context),
        "edges.a-link-is-visible-from-both-sides" => LinkVisible(context),
        "edges.unlinking-a-different-target-keeps-the-link" => UnlinkOther(context),
        "edges.unlinking-the-target-removes-the-link" => UnlinkTarget(context),
        "edges.clear-all-unlink-removes-every-link-from-the-owner" => ClearAll(context),
        "edges.batched-queries-project-one-id-parent-pair-per-link" => BatchedPairs(context),
        "transactions.return-the-work-result" => TransactionResult(context),
        "transactions.roll-back-and-rethrow-when-the-work-throws" => TransactionRollback(context),
        _ => throw new InvalidOperationException($"No conformance case is named {name}."),
    };

    private static async Task InsertReadsBack(Context c)
    {
        var id = await c.Insert(c.Names.Entity, new() { ["name"] = "first" }).ConfigureAwait(false);
        var record = await c.Adaptor.GetAsync(c.Names.Entity, id, c.Token).ConfigureAwait(false);

        c.Expect(
            record is not null && Equals(record.Value("name"), "first"),
            "GetAsync did not read back the value just written.");

        await c.Cleanup(c.Names.Entity, id).ConfigureAwait(false);
    }

    private static async Task GetOfMissing(Context c)
    {
        var id = await c.Insert(c.Names.Entity, new() { ["name"] = "present" }).ConfigureAwait(false);
        var missing = EntityId.Of(id.Raw() + "-missing");

        c.Expect(
            await c.Adaptor.GetAsync(c.Names.Entity, missing, c.Token).ConfigureAwait(false) is null,
            "GetAsync returned a record for an id nothing wrote.");

        await c.Cleanup(c.Names.Entity, id).ConfigureAwait(false);
    }

    private static async Task GetManyReturnsExisting(Context c)
    {
        var id = await c.Insert(c.Names.Entity, new() { ["name"] = "present" }).ConfigureAwait(false);
        var missing = EntityId.Of(id.Raw() + "-missing");
        var many = await c.Adaptor.GetManyAsync(c.Names.Entity, [id, missing], c.Token).ConfigureAwait(false);

        c.Expect(
            many.Count == 1 && many[0].Id == id,
            "GetManyAsync did not return exactly the row that exists.");

        await c.Cleanup(c.Names.Entity, id).ConfigureAwait(false);
    }

    private static async Task UpdateIsVisible(Context c)
    {
        var id = await c.Insert(c.Names.Entity, new() { ["name"] = "first" }).ConfigureAwait(false);

        await c.Adaptor.WriteAsync(new WriteBatch(new Update(c.Names.Entity, id, new Dictionary<string, object?> { ["name"] = "updated" })), c.Token)
            .ConfigureAwait(false);
        var updated = await c.Adaptor.GetAsync(c.Names.Entity, id, c.Token).ConfigureAwait(false);

        c.Expect(
            updated is not null && Equals(updated.Value("name"), "updated"),
            "A written Update was not visible to a later GetAsync.");

        await c.Cleanup(c.Names.Entity, id).ConfigureAwait(false);
    }

    private static async Task DeleteRemoves(Context c)
    {
        var id = await c.Insert(c.Names.Entity, new() { ["name"] = "doomed" }).ConfigureAwait(false);

        await c.Adaptor.WriteAsync(new WriteBatch(new Delete(c.Names.Entity, id)), c.Token).ConfigureAwait(false);

        c.Expect(
            await c.Adaptor.GetAsync(c.Names.Entity, id, c.Token).ConfigureAwait(false) is null,
            "GetAsync still finds a row after a written Delete.");
    }

    private static async Task EqualsFilter(Context c)
    {
        var ids = await c.Seed(("alpha", 3), ("beta", 1), ("gamma", 2)).ConfigureAwait(false);
        var page = await c.Adaptor.QueryAsync(Criteria.For(c.Names.Entity).Where(Filter.Equal("name", "beta")), c.Token).ConfigureAwait(false);

        c.Expect(
            page.Items.Count == 1 && Equals(page.Items[0].Value("name"), "beta"),
            "QueryAsync with an equals filter did not narrow to the matching row.");

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task Ordering(Context c, Direction direction)
    {
        var ids = await c.Seed(("alpha", 3), ("beta", 1), ("gamma", 2)).ConfigureAwait(false);
        var order = direction == Direction.Ascending ? Order.Ascending("rank") : Order.Descending("rank");
        var page = await c.Adaptor.QueryAsync(Criteria.For(c.Names.Entity).OrderBy(order), c.Token).ConfigureAwait(false);
        var names = page.Items.Select(record => record.Value("name")).ToList();
        string[] expected = direction == Direction.Ascending ? ["beta", "gamma", "alpha"] : ["alpha", "gamma", "beta"];

        c.Expect(
            names.SequenceEqual(expected),
            $"QueryAsync ordered {direction.ToString().ToLowerInvariant()} returned [{string.Join(", ", names)}], not [{string.Join(", ", expected)}].");

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task CountTotal(Context c)
    {
        var ids = await c.Seed(("alpha", 3), ("beta", 1), ("gamma", 2)).ConfigureAwait(false);
        var total = await c.Adaptor.CountAsync(Criteria.For(c.Names.Entity).Unbounded(), c.Token).ConfigureAwait(false);

        c.Expect(total >= 3, $"CountAsync reported {total} for a criteria matching at least 3 rows.");

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task CountFiltered(Context c)
    {
        var ids = await c.Seed(("alpha", 3), ("beta", 1), ("gamma", 2)).ConfigureAwait(false);
        var count = await c.Adaptor.CountAsync(Criteria.For(c.Names.Entity).Where(Filter.Equal("name", "beta")), c.Token).ConfigureAwait(false);

        c.Expect(count == 1, $"CountAsync with a filter reported {count} instead of 1.");

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task SecondPage(Context c)
    {
        var ids = await c.Seed(("one", 1), ("two", 2), ("three", 3)).ConfigureAwait(false);
        var criteria = Criteria.For(c.Names.Entity).OrderBy(Order.Ascending("rank")).Take(2);
        var first = await c.Adaptor.QueryAsync(criteria, c.Token).ConfigureAwait(false);

        c.Expect(
            first.Items.Count == 2 && first.HasMore,
            "A first page of 2 over 3 rows did not come back full with more to fetch.");

        if (first.HasMore)
        {
            var second = await c.Adaptor.QueryAsync(criteria.Take(2, first.Next), c.Token).ConfigureAwait(false);

            c.Expect(
                second.Items.Count == 1 && !second.HasMore,
                "The second page did not carry the one remaining row with nothing further.");
        }

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task TakeZero(Context c)
    {
        var page = await c.Adaptor.QueryAsync(Criteria.For(c.Names.Entity).Take(0), c.Token).ConfigureAwait(false);

        c.Expect(page.Items.Count == 0 && !page.HasMore, "Take(0) must return an empty terminal page.");
    }

    private static async Task TakeNegative(Context c)
    {
        try
        {
            await c.Adaptor.QueryAsync(Criteria.For(c.Names.Entity).Take(-1), c.Token).ConfigureAwait(false);
            c.Expect(false, "Take(-1) must reject a negative page size.");
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            // Rejected, as required. Which exception is the adaptor's own choice.
        }
    }

    private static async Task TiesAndNoOrder(Context c)
    {
        var ids = await c.SeedMany(Enumerable.Range(1, 10).Select(n => ($"tie-{n}", 1))).ConfigureAwait(false);
        var expected = ids.OrderBy(id => id, IdOrder.Instance).ToList();

        foreach (var criteria in new[] { Criteria.For(c.Names.Entity), Criteria.For(c.Names.Entity).OrderBy(Order.Ascending("rank")) })
        {
            var seen = new List<EntityId>();
            ICursor? cursor = null;

            for (var step = 0; step < 5; step++)
            {
                var page = await c.Adaptor.QueryAsync(criteria.Take(3, cursor), c.Token).ConfigureAwait(false);
                seen.AddRange(page.Items.Select(record => record.Id));
                cursor = page.Next;

                if (cursor is null)
                {
                    break;
                }
            }

            c.Expect(
                seen.SequenceEqual(expected) && cursor is null,
                "Paged tied and no-order rows must appear exactly once in ascending id order.");
        }

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task OversizedPages(Context c)
    {
        var ids = await c.SeedMany(Enumerable.Range(0, Page<Record>.MaxLimit + 1).Select(n => ($"bounded-{n}", n))).ConfigureAwait(false);
        var first = await c.Adaptor.QueryAsync(Criteria.For(c.Names.Entity).Take(Page<Record>.MaxLimit + 50), c.Token).ConfigureAwait(false);

        // An offset cursor must stand at the effective size. A keyset cursor has no number to check, so the walk below is what
        // proves it resumes where the page stopped.
        c.Expect(
            first.Items.Count == Page<Record>.MaxLimit
                && first.HasMore
                && (first.Next is KeysetCursor || OffsetCursor.From(first.Next).Value == Page<Record>.MaxLimit),
            "Oversized pages must cap at Page.MaxLimit with a cursor that resumes after the rows returned.");

        if (first.HasMore)
        {
            var last = await c.Adaptor.QueryAsync(Criteria.For(c.Names.Entity).Take(Page<Record>.MaxLimit + 50, first.Next), c.Token).ConfigureAwait(false);
            var walk = first.Items.Concat(last.Items).Select(record => record.Id).ToList();

            c.Expect(
                walk.SequenceEqual(ids.OrderBy(id => id, IdOrder.Instance)) && !last.HasMore,
                "A capped page walk must resume after its effective size without gaps or duplicates.");
        }

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task KeysetResume(Context c)
    {
        // Ties on the sort column, in both directions: the position is (rank, id), and the row at it is never
        // returned again.
        var ids = await c.SeedMany([("a", 1), ("b", 1), ("c", 2), ("d", 2), ("e", 3), ("f", 3), ("g", 3)]).ConfigureAwait(false);

        foreach (var order in new[] { Order.Ascending("rank"), Order.Descending("rank") })
        {
            var criteria = Criteria.For(c.Names.Entity).OrderBy(order);
            var everything = await c.Adaptor.QueryAsync(criteria.Take(Page<Record>.MaxLimit), c.Token).ConfigureAwait(false);
            var expected = everything.Items.Select(record => record.Id).ToList();
            var seen = new List<EntityId>();
            KeysetCursor? cursor = null;

            for (var step = 0; step < 20; step++)
            {
                var page = await c.Adaptor.QueryAsync(criteria.Take(2, cursor), c.Token).ConfigureAwait(false);
                seen.AddRange(page.Items.Select(record => record.Id));

                if (page.Items.Count == 0 || !page.HasMore)
                {
                    break;
                }

                // Built by hand from the last row, as a caller that does not trust the adaptor's own cursor does.
                var lastRow = page.Items[^1];
                cursor = new KeysetCursor([lastRow.Value("rank")], lastRow.Id);
            }

            c.Expect(
                seen.SequenceEqual(expected),
                $"A KeysetCursor must resume strictly after its position ({order.Direction.ToString().ToLowerInvariant()} rank): "
                + $"walked [{string.Join(", ", seen)}], expected [{string.Join(", ", expected)}].");
        }

        await c.Cleanup(c.Names.Entity, ids).ConfigureAwait(false);
    }

    private static async Task LinkVisible(Context c)
    {
        var (owners, target) = await c.SeedLinked().ConfigureAwait(false);
        var owner = owners["owner"];

        var declaring = await c.Adaptor.QueryAsync(
            Criteria.For(c.Names.Entity).LinkedTo(EdgeFilter.Back(c.Names.Entity, c.Names.Edge, target)), c.Token).ConfigureAwait(false);

        c.Expect(
            declaring.Items.Count == 1 && declaring.Items[0].Id == owner,
            "A Link was not visible to a query LinkedTo(EdgeFilter.Back()) on the declaring side.");

        var targets = await c.Adaptor.QueryAsync(
            Criteria.For(c.Names.Related).LinkedTo(EdgeFilter.Along(c.Names.Entity, c.Names.Edge, owner)), c.Token).ConfigureAwait(false);

        c.Expect(
            targets.Items.Count == 1 && targets.Items[0].Id == target,
            "A Link was not visible to a query LinkedTo(EdgeFilter.Along()) on the target side.");

        await c.Unlink(owner).ConfigureAwait(false);
        await c.Cleanup(c.Names.Entity, owners.Values).ConfigureAwait(false);
        await c.Cleanup(c.Names.Related, target).ConfigureAwait(false);
    }

    private static async Task UnlinkOther(Context c)
    {
        var (owners, target) = await c.SeedLinked().ConfigureAwait(false);
        var owner = owners["owner"];
        var other = await c.Insert(c.Names.Related, new() { ["name"] = "other-target" }).ConfigureAwait(false);

        await c.Adaptor.WriteAsync(new WriteBatch(new Unlink(c.Names.Entity, c.Names.Edge, owner, other)), c.Token).ConfigureAwait(false);

        foreach (var criteria in new[]
        {
            Criteria.For(c.Names.Entity).LinkedTo(EdgeFilter.Back(c.Names.Entity, c.Names.Edge, target)),
            Criteria.For(c.Names.Related).LinkedTo(EdgeFilter.Along(c.Names.Entity, c.Names.Edge, owner)),
        })
        {
            c.Expect(
                (await c.Adaptor.QueryAsync(criteria, c.Token).ConfigureAwait(false)).Items.Count == 1,
                "Targeted unlink of a different target must retain the existing link.");
        }

        await c.Unlink(owner).ConfigureAwait(false);
        await c.Cleanup(c.Names.Entity, owners.Values).ConfigureAwait(false);
        await c.Cleanup(c.Names.Related, target, other).ConfigureAwait(false);
    }

    private static async Task UnlinkTarget(Context c)
    {
        var (owners, target) = await c.SeedLinked().ConfigureAwait(false);
        var owner = owners["owner"];

        await c.Adaptor.WriteAsync(new WriteBatch(new Unlink(c.Names.Entity, c.Names.Edge, owner, target)), c.Token).ConfigureAwait(false);

        var after = await c.Adaptor.QueryAsync(
            Criteria.For(c.Names.Entity).LinkedTo(EdgeFilter.Back(c.Names.Entity, c.Names.Edge, target)), c.Token).ConfigureAwait(false);

        c.Expect(after.Items.Count == 0, "A row was still linked after an Unlink named it explicitly.");

        await c.Cleanup(c.Names.Entity, owners.Values).ConfigureAwait(false);
        await c.Cleanup(c.Names.Related, target).ConfigureAwait(false);
    }

    private static async Task ClearAll(Context c)
    {
        var (owners, target) = await c.SeedLinked().ConfigureAwait(false);
        var owner = owners["owner"];

        await c.Adaptor.WriteAsync(new WriteBatch(new Unlink(c.Names.Entity, c.Names.Edge, owner)), c.Token).ConfigureAwait(false);

        var after = await c.Adaptor.QueryAsync(
            Criteria.For(c.Names.Related).LinkedTo(EdgeFilter.Along(c.Names.Entity, c.Names.Edge, owner)), c.Token).ConfigureAwait(false);

        c.Expect(after.Items.Count == 0, "Clear-all unlink must remove every link from the owner.");

        await c.Cleanup(c.Names.Entity, owners.Values).ConfigureAwait(false);
        await c.Cleanup(c.Names.Related, target).ConfigureAwait(false);
    }

    private static async Task BatchedPairs(Context c)
    {
        var owners = await c.Seed(("owner", 1), ("other-owner", 2)).ConfigureAwait(false);
        var owner = owners["owner"];
        var otherOwner = owners["other-owner"];
        var target = await c.Insert(c.Names.Related, new() { ["name"] = "target" }).ConfigureAwait(false);
        var otherTarget = await c.Insert(c.Names.Related, new() { ["name"] = "other-target" }).ConfigureAwait(false);

        await c.Adaptor.WriteAsync(new WriteBatch(
            new Link(c.Names.Entity, c.Names.Edge, owner, target),
            new Link(c.Names.Entity, c.Names.Edge, otherOwner, otherTarget)), c.Token).ConfigureAwait(false);

        var forward = new List<(string, string)> { (target.ToString(), owner.ToString()), (otherTarget.ToString(), otherOwner.ToString()) };
        var reverse = new List<(string, string)> { (owner.ToString(), target.ToString()), (otherOwner.ToString(), otherTarget.ToString()) };

        if (c.Names.SharedTargets)
        {
            await c.Adaptor.WriteAsync(new WriteBatch(new Link(c.Names.Entity, c.Names.Edge, otherOwner, target)), c.Token).ConfigureAwait(false);
            forward.Add((target.ToString(), otherOwner.ToString()));
            reverse.Add((otherOwner.ToString(), target.ToString()));
        }

        var along = await c.Adaptor.QueryAsync(
            Criteria.For(c.Names.Related).LinkedTo(EdgeFilter.Along(c.Names.Entity, c.Names.Edge, owner, otherOwner)), c.Token).ConfigureAwait(false);
        var back = await c.Adaptor.QueryAsync(
            Criteria.For(c.Names.Entity).LinkedTo(EdgeFilter.Back(c.Names.Entity, c.Names.Edge, target, otherTarget)), c.Token).ConfigureAwait(false);

        c.Expect(
            Pairs(along.Items).SequenceEqual(forward.Order()) && Pairs(back.Items).SequenceEqual(reverse.Order()),
            "Batched relationship queries must project exactly one (id, parent) pair per link in both directions.");

        await c.Unlink(owner).ConfigureAwait(false);
        await c.Unlink(otherOwner).ConfigureAwait(false);
        await c.Cleanup(c.Names.Entity, owners.Values).ConfigureAwait(false);
        await c.Cleanup(c.Names.Related, target, otherTarget).ConfigureAwait(false);

        static IEnumerable<(string, string)> Pairs(IEnumerable<Record> rows) =>
            rows.Select(row => (row.Id.ToString(), Convert.ToString(row.Value(EdgeFilter.ParentColumn), System.Globalization.CultureInfo.InvariantCulture) ?? string.Empty)).Order();
    }

    private static async Task TransactionResult(Context c)
    {
        var value = await c.Adaptor.TransactionAsync(_ => Task.FromResult(42), c.Token).ConfigureAwait(false);

        c.Expect(value == 42, "TransactionAsync did not return the work callback's own return value.");
    }

    private static async Task TransactionRollback(Context c)
    {
        var threw = false;

        try
        {
            await c.Adaptor.TransactionAsync<int>(
                async token =>
                {
                    await c.Adaptor.WriteAsync(
                        new WriteBatch(new Insert(c.Names.Entity, new PendingId(c.Names.Entity), new Dictionary<string, object?> { ["name"] = "doomed" })),
                        token).ConfigureAwait(false);

                    throw new InvalidOperationException("conformance: deliberate failure");
                },
                c.Token).ConfigureAwait(false);
        }
        catch (InvalidOperationException exception) when (exception.Message == "conformance: deliberate failure")
        {
            threw = true;
        }

        c.Expect(threw, "TransactionAsync swallowed an exception thrown by its work callback.");

        var survivors = await c.Adaptor.QueryAsync(
            Criteria.For(c.Names.Entity).Where(Filter.Equal("name", "doomed")), c.Token).ConfigureAwait(false);

        if (survivors.Items.Count > 0)
        {
            c.Expect(false, "A write inside a transaction that threw was not rolled back.");

            await c.Cleanup(c.Names.Entity, survivors.Items.Select(record => record.Id)).ConfigureAwait(false);
        }
    }

    /// <summary>What a case is running against, and where it records what it finds.</summary>
    private sealed class Context(IStorageAdaptor adaptor, ConformanceNames names, Problems problems, CancellationToken token)
    {
        public IStorageAdaptor Adaptor { get; } = adaptor;

        public ConformanceNames Names { get; } = names;

        public CancellationToken Token { get; } = token;

        public void Expect(bool condition, string failure)
        {
            if (!condition)
            {
                problems.Add(failure);
            }
        }

        public async Task<EntityId> Insert(string entity, Dictionary<string, object?> values)
        {
            var pending = new PendingId(entity);
            var result = await Adaptor.WriteAsync(new WriteBatch(new Insert(entity, pending, values)), Token).ConfigureAwait(false);

            if (!result.WasAssigned(pending))
            {
                problems.Add("WriteAsync did not assign an id to an Insert.");

                throw new ConformanceFailureException("insert", problems.All);
            }

            return result.IdFor(pending);
        }

        public async Task<Dictionary<string, EntityId>> Seed(params (string Name, int Rank)[] rows)
        {
            var ids = new Dictionary<string, EntityId>();

            foreach (var (name, rank) in rows)
            {
                ids[name] = await Insert(Names.Entity, new() { ["name"] = name, ["rank"] = (long)rank }).ConfigureAwait(false);
            }

            return ids;
        }

        /// <summary>Many rows, in batches small enough for a store with a limit on operations per write.</summary>
        public async Task<List<EntityId>> SeedMany(IEnumerable<(string Name, int Rank)> rows)
        {
            var ids = new List<EntityId>();

            foreach (var batch in rows.Chunk(50))
            {
                var pending = batch.Select(row => (row, id: new PendingId(Names.Entity))).ToList();
                var result = await Adaptor.WriteAsync(
                    new WriteBatch([.. pending.Select(item => new Insert(
                        Names.Entity,
                        item.id,
                        new Dictionary<string, object?> { ["name"] = item.row.Name, ["rank"] = (long)item.row.Rank }))]),
                    Token).ConfigureAwait(false);

                ids.AddRange(pending.Select(item => result.IdFor(item.id)));
            }

            return ids;
        }

        public async Task<(Dictionary<string, EntityId> Owners, EntityId Target)> SeedLinked()
        {
            var owners = await Seed(("owner", 1), ("other-owner", 2)).ConfigureAwait(false);
            var target = await Insert(Names.Related, new() { ["name"] = "target" }).ConfigureAwait(false);

            await Adaptor.WriteAsync(new WriteBatch(new Link(Names.Entity, Names.Edge, owners["owner"], target)), Token).ConfigureAwait(false);

            return (owners, target);
        }

        public Task<WriteResult> Unlink(EntityId owner) =>
            Adaptor.WriteAsync(new WriteBatch(new Unlink(Names.Entity, Names.Edge, owner)), Token);

        public Task Cleanup(string entity, params EntityId[] ids) => Cleanup(entity, (IEnumerable<EntityId>)ids);

        public async Task Cleanup(string entity, IEnumerable<EntityId> ids)
        {
            foreach (var chunk in ids.Chunk(50))
            {
                await Adaptor.WriteAsync(new WriteBatch([.. chunk.Select(id => new Delete(entity, id))]), Token).ConfigureAwait(false);
            }
        }

        public Task Cleanup(string entity, Dictionary<string, EntityId> ids) => Cleanup(entity, ids.Values);
    }

    private sealed class Problems
    {
        private readonly List<string> found = [];

        public bool Any => found.Count > 0;

        public IReadOnlyList<string> All => found;

        public void Add(string problem) => found.Add(problem);
    }

    /// <summary>Ascending id order for ids that are all numbers or all text.</summary>
    private sealed class IdOrder : IComparer<EntityId>
    {
        public static IdOrder Instance { get; } = new();

        public int Compare(EntityId? left, EntityId? right) => (left!.Raw(), right!.Raw()) switch
        {
            (long a, long b) => a.CompareTo(b),
            (string a, string b) => string.CompareOrdinal(a, b),
            (long, _) => -1,
            _ => 1,
        };
    }
}
