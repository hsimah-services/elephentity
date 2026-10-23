using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Sqlite.Manifest;
using Microsoft.Data.Sqlite;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Sqlite.Tests;

public sealed class SqliteAdaptorTests : IAsyncLifetime
{
    private SqliteStorageManifest manifest = null!;
    private SqliteConnection connection = null!;
    private SqliteAdaptor adaptor = null!;

    private static CancellationToken Token => TestContext.Current.CancellationToken;

    public async ValueTask InitializeAsync()
    {
        manifest = Schema.Blog();
        connection = await Schema.InstalledAsync(manifest);
        adaptor = new SqliteAdaptor(connection, manifest);
    }

    public ValueTask DisposeAsync()
    {
        adaptor.Dispose();
        connection.Dispose();

        return ValueTask.CompletedTask;
    }

    private async Task<EntityId> InsertAsync(string entity, Dictionary<string, object?> values)
    {
        var pending = new PendingId(entity);
        var result = await adaptor.WriteAsync(new WriteBatch(new Insert(entity, pending, values)), Token);

        return result.IdFor(pending);
    }

    private Task<EntityId> PostAsync(string slug, long? rank = null) =>
        InsertAsync("Post", new() { ["title"] = slug, ["slug"] = slug, ["rank"] = rank });

    private Task<WriteResult> LinkAsync(string entity, string edge, EntityId from, EntityId to) =>
        adaptor.WriteAsync(new WriteBatch(new Link(entity, edge, from, to)), Token);

    private async Task<List<string>> SlugsAsync(Criteria criteria) =>
        [.. (await adaptor.QueryAsync(criteria, Token)).Items.Select(record => (string)record.Value("slug")!)];

    [Fact]
    public void ItDeclaresTransactionsAndForeignKeys()
    {
        Assert.Equal(
            [Elephentity.Runtime.Capability.Capability.Transactions, Elephentity.Runtime.Capability.Capability.ForeignKeys],
            adaptor.Capabilities.All());
        Assert.Equal("sqlite", adaptor.Capabilities.Adaptor);
    }

    [Fact]
    public async Task IdsAreSequentialIntegersAndValuesComeBackAsTheirFields()
    {
        var first = await PostAsync("a", 7);
        var second = await PostAsync("b");
        var record = await adaptor.GetAsync("Post", first, Token);

        Assert.Equal(EntityId.Of(1), first);
        Assert.Equal(EntityId.Of(2), second);
        Assert.Equal(7L, record!.Value("rank"));
        Assert.Equal("a", record.Value("title"));
        Assert.Null((await adaptor.GetAsync("Post", second, Token))!.Value("rank"));
        Assert.True((await adaptor.GetAsync("Post", second, Token))!.Has("rank"));
    }

    [Fact]
    public async Task AFieldIsReadBackUnderItsFieldNameNotItsColumnName()
    {
        var post = await PostAsync("a");
        var comment = await InsertAsync("Comment", new() { ["body"] = "hi", ["type"] = "note" });
        await LinkAsync("Post", "comments", post, comment);

        var record = await adaptor.GetAsync("Comment", comment, Token);

        Assert.Equal("note", record!.Value("type"));
        Assert.False(record.Has("kind"));
        // A column no field is stored in keeps its own name, as the link column does.
        Assert.Equal(1L, record.Value("post_id"));
    }

    [Fact]
    public async Task BooleansAreStoredAsIntegersAndNullsAsNull()
    {
        var id = await InsertAsync("Post", new() { ["title"] = "t", ["slug"] = "s", ["pinned"] = true, ["views"] = null });
        var record = await adaptor.GetAsync("Post", id, Token);

        Assert.Equal(1L, record!.Value("pinned"));
        Assert.Null(record.Value("views"));
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT typeof(pinned) = 'integer' FROM post"));

        await adaptor.WriteAsync(new WriteBatch(new Update("Post", id, new Dictionary<string, object?> { ["pinned"] = false })), Token);

        Assert.Equal(0L, (await adaptor.GetAsync("Post", id, Token))!.Value("pinned"));
        Assert.Single((await adaptor.QueryAsync(Criteria.For("Post").Where(Filter.Equal("pinned", false)), Token)).Items);
    }

    [Fact]
    public async Task AnUpdateChangesOnlyTheFieldsItNamesAndAnEmptyOneChangesNothing()
    {
        var id = await PostAsync("a", 1);

        await adaptor.WriteAsync(new WriteBatch(new Update("Post", id, new Dictionary<string, object?> { ["title"] = "renamed" })), Token);
        await adaptor.WriteAsync(new WriteBatch(new Update("Post", id, new Dictionary<string, object?>())), Token);

        var record = await adaptor.GetAsync("Post", id, Token);

        Assert.Equal("renamed", record!.Value("title"));
        Assert.Equal("a", record.Value("slug"));
    }

    [Fact]
    public async Task AValueOfAnUnsupportedTypeIsRefusedNamingTheField()
    {
        var failure = await Assert.ThrowsAsync<ArgumentException>(() =>
            InsertAsync("Post", new() { ["title"] = new object(), ["slug"] = "s" }));

        Assert.Contains("title", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task FiltersCompareAndEscapeLikePatterns()
    {
        await PostAsync("100%_sure", 1);
        await PostAsync("100 per cent", 2);
        await PostAsync("a\\b", 3);
        await PostAsync("plain", null);

        Assert.Equal(["100%_sure"], await SlugsAsync(Criteria.For("Post").Where(Filter.Contains("slug", "%_"))));
        Assert.Equal(["a\\b"], await SlugsAsync(Criteria.For("Post").Where(Filter.Contains("slug", "\\"))));
        Assert.Equal(["100%_sure", "100 per cent"], await SlugsAsync(Criteria.For("Post").Where(Filter.StartsWith("slug", "100"))));
        Assert.Equal(["100 per cent", "a\\b"], await SlugsAsync(Criteria.For("Post").Where(Filter.GreaterThan("rank", 1))));
        Assert.Equal(["100%_sure", "100 per cent"], await SlugsAsync(Criteria.For("Post").Where(Filter.LessThanOrEqual("rank", 2))));
        Assert.Equal(["plain"], await SlugsAsync(Criteria.For("Post").Where(Filter.IsNull("rank"))));
        Assert.Equal(3, (await adaptor.QueryAsync(Criteria.For("Post").Where(Filter.IsNotNull("rank")), Token)).Items.Count);
        Assert.Equal(["100%_sure", "a\\b"], await SlugsAsync(Criteria.For("Post").Where(Filter.In("rank", [1L, 3L]))));
        Assert.Equal(["100 per cent"], await SlugsAsync(Criteria.For("Post").Where(Filter.NotIn("rank", [1L, 3L]))));
        Assert.Empty(await SlugsAsync(Criteria.For("Post").Where(Filter.In("rank", []))));
        Assert.Equal(4, (await adaptor.QueryAsync(Criteria.For("Post").Where(Filter.NotIn("rank", [])), Token)).Items.Count);
    }

    [Fact]
    public async Task ANotEqualFilterLeavesOutRowsWithNoValue()
    {
        // SQL semantics, as in PHP: NULL <> 1 is not true. (The memory driver includes such rows.)
        await PostAsync("one", 1);
        await PostAsync("two", 2);
        await PostAsync("none");

        Assert.Equal(["two"], await SlugsAsync(Criteria.For("Post").Where(Filter.NotEqual("rank", 1))));
    }

    [Fact]
    public async Task AFilterOnAFieldTheTableDoesNotHaveIsRefused()
    {
        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            adaptor.QueryAsync(Criteria.For("Post").Where(Filter.Equal("nope", 1)), Token));

        Assert.Contains("no column", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task AValueNeverReachesTheSql()
    {
        await PostAsync("safe");

        var page = await adaptor.QueryAsync(Criteria.For("Post").Where(Filter.Equal("slug", "x' OR '1'='1")), Token);

        Assert.Empty(page.Items);
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM post"));
    }

    [Fact]
    public async Task OffsetPagingWalksEveryRowOnce()
    {
        for (var n = 1; n <= 7; n++)
        {
            await PostAsync($"p{n}", n % 3);
        }

        var seen = new List<string>();
        ICursor? cursor = null;
        var steps = 0;

        do
        {
            Assert.True(++steps <= 10, "The walk did not end.");

            var page = await adaptor.QueryAsync(Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(3, cursor), Token);

            seen.AddRange(page.Items.Select(record => (string)record.Value("slug")!));
            cursor = page.Next;

            Assert.True(cursor is null || cursor is OffsetCursor);
        }
        while (cursor is not null);

        Assert.Equal(["p2", "p5", "p1", "p4", "p7", "p3", "p6"], seen);
    }

    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public async Task KeysetPagingResumesStrictlyAfterThePositionThroughNullsAndTies(bool descending)
    {
        long?[] ranks = [null, 1, 1, 2, null, 2, 3, 3, 1];

        for (var n = 0; n < ranks.Length; n++)
        {
            await PostAsync($"p{n}", ranks[n]);
        }

        var criteria = Criteria.For("Post").OrderBy(descending ? Order.Descending("rank") : Order.Ascending("rank"));
        var expected = (await adaptor.QueryAsync(criteria.Take(100), Token)).Items.Select(record => record.Id).ToList();
        var seen = new List<EntityId>();
        ICursor? cursor = null;
        var pages = 0;

        do
        {
            Assert.True(pages <= ranks.Length, "The walk did not end.");

            var page = await adaptor.QueryAsync(criteria.Take(2, cursor), Token);

            seen.AddRange(page.Items.Select(record => record.Id));
            cursor = page.Next;
            pages++;

            // Once the walk is on a keyset it stays on one.
            Assert.True(cursor is null || cursor is OffsetCursor && pages == 1 || cursor is KeysetCursor);

            if (cursor is OffsetCursor)
            {
                // Switch to a position built by hand from the last row, as a caller that does not trust the cursor does.
                cursor = new KeysetCursor([page.Items[^1].Value("rank")], page.Items[^1].Id);
            }
        }
        while (cursor is not null);

        Assert.Equal(expected, seen);
        Assert.Equal(ranks.Length, seen.Distinct().Count());
    }

    [Fact]
    public async Task AKeysetCursorWithTheWrongNumberOfSortValuesIsRefused()
    {
        await PostAsync("a", 1);

        await Assert.ThrowsAsync<ArgumentException>(() =>
            adaptor.QueryAsync(Criteria.For("Post").OrderBy(Order.Ascending("rank")).Take(2, new KeysetCursor([], EntityId.Of(1))), Token));
    }

    [Fact]
    public async Task AnUnreadableCursorRestartsTheList()
    {
        await PostAsync("a", 1);

        var page = await adaptor.QueryAsync(Criteria.For("Post").Take(5, Cursor.Of("garbage")), Token);

        Assert.Single(page.Items);
    }

    [Fact]
    public async Task AnOversizedPageIsCappedAndAnEmptyOneIsTerminal()
    {
        await PostAsync("a");

        Assert.Empty((await adaptor.QueryAsync(Criteria.For("Post").Take(0), Token)).Items);
        await Assert.ThrowsAnyAsync<Exception>(() => adaptor.QueryAsync(Criteria.For("Post").Take(-1), Token));
        Assert.Single((await adaptor.QueryAsync(Criteria.For("Post").Take(50_000), Token)).Items);
    }

    [Fact]
    public async Task CountIgnoresPagingAndHonoursFiltersAndLinks()
    {
        var first = await PostAsync("a", 1);
        await PostAsync("b", 2);
        var tag = await InsertAsync("Tag", new() { ["label"] = "x" });
        await LinkAsync("Post", "tags", first, tag);

        Assert.Equal(2, await adaptor.CountAsync(Criteria.For("Post"), Token));
        Assert.Equal(2, await adaptor.CountAsync(Criteria.For("Post").Take(1), Token));
        Assert.Equal(1, await adaptor.CountAsync(Criteria.For("Post").Where(Filter.Equal("rank", 2)), Token));
        Assert.Equal(1, await adaptor.CountAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "tags", tag)), Token));
    }

    [Fact]
    public async Task GetManyReturnsOnlyTheRowsThatExist()
    {
        var a = await PostAsync("a");
        var b = await PostAsync("b");

        Assert.Empty(await adaptor.GetManyAsync("Post", [], Token));
        Assert.Equal(2, (await adaptor.GetManyAsync("Post", [a, b, EntityId.Of(99)], Token)).Count);
    }

    [Fact]
    public async Task AManyToOneEdgeLivesOnTheDeclaringRowAndReadsBothWays()
    {
        var post = await PostAsync("a");
        var other = await PostAsync("b");
        var author = await InsertAsync("Author", new() { ["name"] = "n" });

        await LinkAsync("Post", "author", post, author);

        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT author_id FROM post WHERE id = 1"));
        Assert.Equal(["a"], await SlugsAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "author", author))));
        Assert.Equal([author], (await adaptor.QueryAsync(Criteria.For("Author").LinkedTo(EdgeFilter.Along("Post", "author", post)), Token)).Items.Select(record => record.Id));
        Assert.Empty((await adaptor.QueryAsync(Criteria.For("Author").LinkedTo(EdgeFilter.Along("Post", "author", other)), Token)).Items);

        // Unlinking a different target keeps the link; the right one, or none named, removes it.
        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "author", post, EntityId.Of(99))), Token);
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT author_id FROM post WHERE id = 1"));
        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "author", post, author)), Token);
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT author_id IS NULL FROM post WHERE id = 1"));
        await LinkAsync("Post", "author", post, author);
        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "author", post)), Token);
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT author_id IS NULL FROM post WHERE id = 1"));
    }

    [Fact]
    public async Task AOneToManyEdgeLivesOnTheTargetRows()
    {
        var post = await PostAsync("a");
        var other = await PostAsync("b");
        var first = await InsertAsync("Comment", new() { ["body"] = "1" });
        var second = await InsertAsync("Comment", new() { ["body"] = "2" });

        await LinkAsync("Post", "comments", post, first);
        await LinkAsync("Post", "comments", post, second);
        await LinkAsync("Post", "comments", other, second);

        // Linking moves the target: a comment belongs to one post.
        Assert.Equal(2L, await Schema.ScalarAsync(connection, "SELECT post_id FROM comment WHERE id = 2"));
        Assert.Equal([first], (await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", post)), Token)).Items.Select(record => record.Id));
        Assert.Equal(["b"], await SlugsAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "comments", second))));

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "comments", other, first)), Token);
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT post_id FROM comment WHERE id = 1"));
        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "comments", post)), Token);
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM comment WHERE post_id IS NULL"));
    }

    [Fact]
    public async Task AOneToOneEdgeCannotPointTwoRowsAtTheSameTarget()
    {
        var one = await InsertAsync("Author", new() { ["name"] = "one" });
        var two = await InsertAsync("Author", new() { ["name"] = "two" });
        var profile = await InsertAsync("Profile", new() { ["bio"] = "b" });

        await LinkAsync("Author", "profile", one, profile);

        await Assert.ThrowsAsync<SqliteException>(() => LinkAsync("Author", "profile", two, profile));
    }

    [Fact]
    public async Task AManyToManyLinkIsMadeOnceAndReadsBothWays()
    {
        var post = await PostAsync("a");
        var tag = await InsertAsync("Tag", new() { ["label"] = "x" });
        var other = await InsertAsync("Tag", new() { ["label"] = "y" });

        await LinkAsync("Post", "tags", post, tag);
        await LinkAsync("Post", "tags", post, tag);
        await LinkAsync("Post", "tags", post, other);

        Assert.Equal(2L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM post_tags"));

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "tags", post, tag)), Token);
        Assert.Equal([other], (await adaptor.QueryAsync(Criteria.For("Tag").LinkedTo(EdgeFilter.Along("Post", "tags", post)), Token)).Items.Select(record => record.Id));

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "tags", post)), Token);
        Assert.Equal(0L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM post_tags"));
    }

    [Fact]
    public async Task ABatchOfParentsSaysWhichParentEachRowBelongsToWhateverTheShape()
    {
        var posts = new[] { await PostAsync("a"), await PostAsync("b") };
        var comments = new[] { await InsertAsync("Comment", new() { ["body"] = "1" }), await InsertAsync("Comment", new() { ["body"] = "2" }), await InsertAsync("Comment", new() { ["body"] = "3" }) };
        var author = await InsertAsync("Author", new() { ["name"] = "n" });

        await LinkAsync("Post", "comments", posts[0], comments[0]);
        await LinkAsync("Post", "comments", posts[0], comments[1]);
        await LinkAsync("Post", "comments", posts[1], comments[2]);
        await LinkAsync("Post", "author", posts[0], author);
        await LinkAsync("Post", "author", posts[1], author);

        (string, string)[] Pairs(IEnumerable<Record> rows) =>
            [.. rows.Select(row => (row.Id.ToString(), row.Value(EdgeFilter.ParentColumn)!.ToString()!)).Order()];

        var comment = await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", posts)), Token);
        var back = await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "comments", comments)), Token);
        var authors = await adaptor.QueryAsync(Criteria.For("Author").LinkedTo(EdgeFilter.Along("Post", "author", posts)), Token);
        var wrote = await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "author", author, EntityId.Of(77))), Token);

        Assert.Equal([("1", "1"), ("2", "1"), ("3", "2")], Pairs(comment.Items));
        Assert.Equal([("1", "1"), ("1", "2"), ("2", "3")], Pairs(back.Items));
        Assert.Equal([("1", "1"), ("1", "2")], Pairs(authors.Items));
        Assert.Equal([("1", "1"), ("2", "1")], Pairs(wrote.Items));
    }

    [Fact]
    public async Task DeletingARowAnotherPointsAtIsRefusedAndChangesNothing()
    {
        var author = await InsertAsync("Author", new() { ["name"] = "n" });
        var post = await PostAsync("a");

        await LinkAsync("Post", "author", post, author);

        await Assert.ThrowsAsync<SqliteException>(() =>
            adaptor.WriteAsync(new WriteBatch(new Delete("Author", author)), Token));

        Assert.NotNull(await adaptor.GetAsync("Author", author, Token));

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "author", post), new Delete("Author", author)), Token);

        Assert.Null(await adaptor.GetAsync("Author", author, Token));
    }

    [Fact]
    public async Task AUniqueIndexRefusesADuplicateAndTheBatchIsRolledBack()
    {
        await PostAsync("taken");

        var pending = new PendingId("Post");
        var failure = await Assert.ThrowsAsync<SqliteException>(() => adaptor.WriteAsync(
            new WriteBatch(
                new Insert("Post", new PendingId("Post"), new Dictionary<string, object?> { ["title"] = "t", ["slug"] = "fresh" }),
                new Insert("Post", pending, new Dictionary<string, object?> { ["title"] = "t", ["slug"] = "taken" })),
            Token));

        Assert.Contains("UNIQUE", failure.Message, StringComparison.Ordinal);
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM post"));
    }

    [Fact]
    public async Task ACheckConstraintRefusesAValueTheColumnDoesNotAllow()
    {
        await Assert.ThrowsAsync<SqliteException>(() =>
            InsertAsync("Post", new() { ["title"] = "t", ["slug"] = "s", ["pinned"] = 2L }));
    }

    [Fact]
    public async Task ANestedTransactionIsASavepointThatRollsBackByItself()
    {
        var kept = await adaptor.TransactionAsync(async token =>
        {
            var id = await InsertAsync("Tag", new() { ["label"] = "outer" });

            await Assert.ThrowsAsync<InvalidOperationException>(() => adaptor.TransactionAsync<int>(
                async inner =>
                {
                    await adaptor.WriteAsync(new WriteBatch(new Insert("Tag", new PendingId("Tag"), new Dictionary<string, object?> { ["label"] = "inner" })), inner);

                    throw new InvalidOperationException("inner failed");
                },
                token));

            // A write that fails on its own inside the transaction takes only itself back, too.
            await Assert.ThrowsAsync<SqliteException>(() => adaptor.WriteAsync(
                new WriteBatch(new Insert("Post", new PendingId("Post"), new Dictionary<string, object?> { ["title"] = "t" })), token));

            return id;
        }, Token);

        Assert.Equal(["outer"], (await adaptor.QueryAsync(Criteria.For("Tag"), Token)).Items.Select(record => (string)record.Value("label")!));
        Assert.NotNull(await adaptor.GetAsync("Tag", kept, Token));
    }

    [Fact]
    public async Task ATransactionThatThrowsTakesEverythingInsideItBack()
    {
        await Assert.ThrowsAsync<InvalidOperationException>(() => adaptor.TransactionAsync<int>(
            async token =>
            {
                await InsertAsync("Tag", new() { ["label"] = "doomed" });
                await adaptor.WriteAsync(new WriteBatch(new Insert("Tag", new PendingId("Tag"), new Dictionary<string, object?> { ["label"] = "also" })), token);

                throw new InvalidOperationException("no");
            },
            Token));

        Assert.Equal(0L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM tag"));
        // The lock was released and the connection is out of the transaction: the next call works.
        Assert.NotEqual(default, await InsertAsync("Tag", new() { ["label"] = "after" }));
    }

    [Fact]
    public async Task ReadsInsideATransactionSeeItsOwnWrites()
    {
        var count = await adaptor.TransactionAsync(async token =>
        {
            await InsertAsync("Tag", new() { ["label"] = "x" });

            return await adaptor.CountAsync(Criteria.For("Tag"), token);
        }, Token);

        Assert.Equal(1, count);
    }

    [Fact]
    public async Task CallsFromSeveralTasksAreSerialisedOnTheOneConnection()
    {
        await Task.WhenAll(Enumerable.Range(0, 20).Select(n => InsertAsync("Tag", new() { ["label"] = $"t{n}" })));

        Assert.Equal(20, await adaptor.CountAsync(Criteria.For("Tag"), Token));
    }

    [Fact]
    public async Task ACancelledCallDoesNotRunAndDoesNotLeaveTheAdaptorLocked()
    {
        using var cancelled = new CancellationTokenSource();

        await cancelled.CancelAsync();

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => adaptor.CountAsync(Criteria.For("Tag"), cancelled.Token));
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => adaptor.TransactionAsync(_ => Task.FromResult(1), cancelled.Token));

        Assert.Equal(0, await adaptor.CountAsync(Criteria.For("Tag"), Token));
    }

    [Fact]
    public async Task ACancelledBatchIsRolledBack()
    {
        using var cancelled = new CancellationTokenSource();

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => adaptor.TransactionAsync<int>(
            async token =>
            {
                await InsertAsync("Tag", new() { ["label"] = "x" });
                await cancelled.CancelAsync();
                token.ThrowIfCancellationRequested();

                return 1;
            },
            cancelled.Token));

        Assert.Equal(0L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM tag"));
    }

    [Fact]
    public async Task AnAdaptorOnAConnectionStringOpensItselfOnFirstUse()
    {
        var path = Path.Combine(Path.GetTempPath(), $"eleph-{Guid.NewGuid():N}.db");

        try
        {
            await using (var setup = new SqliteConnection($"Data Source={path};Pooling=False"))
            {
                await SqliteInstaller.InstallAsync(setup, manifest, cancellationToken: Token);
            }

            using (var owned = new SqliteAdaptor($"Data Source={path};Pooling=False", manifest))
            {
                await owned.WriteAsync(new WriteBatch(new Insert("Tag", new PendingId("Tag"), new Dictionary<string, object?> { ["label"] = "kept" })), Token);
            }

            using var reopened = new SqliteAdaptor($"Data Source={path};Pooling=False", manifest);

            Assert.Equal(1, await reopened.CountAsync(Criteria.For("Tag"), Token));
        }
        finally
        {
            SqliteConnection.ClearAllPools();

            foreach (var file in Directory.GetFiles(Path.GetTempPath(), Path.GetFileName(path) + "*"))
            {
                File.Delete(file);
            }
        }
    }

    [Fact]
    public async Task TheConnectionIsConfiguredForForeignKeysAndDurability()
    {
        await PostAsync("a");

        Assert.Equal(1L, await Schema.ScalarAsync(connection, "PRAGMA foreign_keys"));
        Assert.Equal(3000L, await Schema.ScalarAsync(connection, "PRAGMA busy_timeout"));
        Assert.Equal(2L, await Schema.ScalarAsync(connection, "PRAGMA synchronous"));
    }

    [Fact]
    public async Task AnEntityTheManifestDoesNotMapIsAnError()
    {
        await Assert.ThrowsAsync<InvalidOperationException>(() => adaptor.GetAsync("Nope", EntityId.Of(1), Token));
        await Assert.ThrowsAsync<InvalidOperationException>(() => LinkAsync("Post", "nope", EntityId.Of(1), EntityId.Of(1)));
    }
}
