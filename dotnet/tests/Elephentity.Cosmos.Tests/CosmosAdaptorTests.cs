using System.Text;
using Elephentity.Cosmos.Manifest;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Verification;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Cosmos.Tests;

/// <summary>
/// What is specific to Cosmos, against the emulator or an account (skipped without one; see
/// <see cref="CosmosTestEnvironment" />). The portable behaviour is <see cref="CosmosConformanceTests" />.
/// </summary>
public sealed class CosmosAdaptorTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    private static CosmosAdaptor Blog(out CosmosStorageManifest manifest)
    {
        manifest = Layouts.Blog($"t-{Guid.NewGuid():N}");

        return CosmosTestEnvironment.Require().Adaptor(manifest);
    }

    private static async Task<EntityId> Insert(IStorageAdaptor adaptor, string entity, Dictionary<string, object?> values)
    {
        var pending = new PendingId(entity);
        var result = await adaptor.WriteAsync(new WriteBatch(new Insert(entity, pending, values)), Token);

        return result.IdFor(pending);
    }

    private static Task<EntityId> Post(IStorageAdaptor adaptor, string title, string? slug = null, long rank = 0) =>
        Insert(adaptor, "Post", new() { ["title"] = title, ["slug"] = slug, ["rank"] = rank, ["views"] = 0L });

    [Fact]
    public async Task ExactlyOneBatchOfOneHundredOperationsCommits()
    {
        var adaptor = Blog(out _);
        var inserts = Enumerable.Range(0, 100)
            .Select(n => (IWriteOperation)new Insert("Tag", new PendingId("Tag"), new Dictionary<string, object?> { ["label"] = $"t{n}" }))
            .ToArray();

        await adaptor.WriteAsync(new WriteBatch(inserts), Token);

        Assert.Equal(100, await adaptor.CountAsync(Criteria.For("Tag").Unbounded(), Token));
    }

    [Fact]
    public async Task ARowReadWithTheWrongEntityIsNotFound()
    {
        var adaptor = Blog(out _);
        var post = await Post(adaptor, "hello");

        Assert.Null(await adaptor.GetAsync("Tag", post, Token));
        Assert.Empty(await adaptor.GetManyAsync("Tag", [post], Token));
        Assert.NotNull(await adaptor.GetAsync("Post", post, Token));
    }

    [Fact]
    public async Task ATakenUniqueValueIsAViolationNamingTheFieldAndTheCaseDoesNotMatter()
    {
        var adaptor = Blog(out _);
        await Post(adaptor, "first", slug: "Hello-World");

        var failure = await Assert.ThrowsAsync<CommitRejectedException>(() => Post(adaptor, "second", slug: "hello-world"));

        Assert.Equal(["Post.slug"], failure.Paths());
        Assert.Equal("field.unique", failure.Violations[0].Violation.Code);
    }

    [Fact]
    public async Task NoValueNeverCollidesWithNoValueAndAnotherEntityMayUseTheSameText()
    {
        var adaptor = Blog(out _);

        await Post(adaptor, "a");
        await Post(adaptor, "b");
        await Insert(adaptor, "Tag", new() { ["label"] = "hello-world" });
        await Post(adaptor, "c", slug: "hello-world");

        Assert.Equal(3, await adaptor.CountAsync(Criteria.For("Post").Unbounded(), Token));
    }

    [Fact]
    public async Task UpdatingAUniqueFieldToATakenValueFailsButKeepingYourOwnIsFine()
    {
        var adaptor = Blog(out _);
        var first = await Post(adaptor, "first", slug: "one");
        await Post(adaptor, "second", slug: "two");

        await adaptor.WriteAsync(new WriteBatch(new Update("Post", first, new Dictionary<string, object?> { ["slug"] = "one", ["title"] = "renamed" })), Token);
        await Assert.ThrowsAnyAsync<Exception>(() =>
            adaptor.WriteAsync(new WriteBatch(new Update("Post", first, new Dictionary<string, object?> { ["slug"] = "two" })), Token));

        Assert.Equal("one", (await adaptor.GetAsync("Post", first, Token))!.Value("slug"));
        Assert.Equal("renamed", (await adaptor.GetAsync("Post", first, Token))!.Value("title"));

        // The slot moved with the field: the old value is free again.
        await adaptor.WriteAsync(new WriteBatch(new Update("Post", first, new Dictionary<string, object?> { ["slug"] = "three" })), Token);
        await Post(adaptor, "reuses one", slug: "one");
    }

    [Fact]
    public async Task UpdatingARowThatIsNotThereNamesIt()
    {
        var adaptor = Blog(out _);

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            adaptor.WriteAsync(new WriteBatch(new Update("Post", EntityId.Of("nope"), new Dictionary<string, object?> { ["title"] = "x" })), Token));

        Assert.Contains("Cannot update Post#nope: no such row.", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task ATransactionIsOneBatchSoAConflictLeavesNothingBehind()
    {
        var adaptor = Blog(out _);
        await Post(adaptor, "taken", slug: "taken");

        await Assert.ThrowsAsync<CommitRejectedException>(() => adaptor.TransactionAsync(
            async token =>
            {
                await adaptor.WriteAsync(new WriteBatch(new Insert("Tag", new PendingId("Tag"), new Dictionary<string, object?> { ["label"] = "innocent" })), token);
                await adaptor.WriteAsync(
                    new WriteBatch(new Insert("Post", new PendingId("Post"), new Dictionary<string, object?> { ["title"] = "dup", ["slug"] = "taken" })),
                    token);

                return 0;
            },
            Token));

        Assert.Equal(0, await adaptor.CountAsync(Criteria.For("Tag").Unbounded(), Token));
    }

    [Fact]
    public async Task ATransactionOnOneAdaptorDoesNotCaptureAWriteToAnother()
    {
        var first = Blog(out _);
        var second = Blog(out _);

        await first.TransactionAsync(
            async token =>
            {
                await second.WriteAsync(new WriteBatch(new Insert("Tag", new PendingId("Tag"), new Dictionary<string, object?> { ["label"] = "elsewhere" })), token);

                return 0;
            },
            Token);

        // It landed in the second adaptor's container at once, and nothing in the first's.
        Assert.Equal(1, await second.CountAsync(Criteria.For("Tag").Unbounded(), Token));
        Assert.Equal(0, await first.CountAsync(Criteria.For("Tag").Unbounded(), Token));
    }

    [Fact]
    public async Task ATransactionOpenedInsideAnotherIsRefused()
    {
        var adaptor = Blog(out _);

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() => adaptor.TransactionAsync(
            token => adaptor.TransactionAsync(_ => Task.FromResult(0), token),
            Token));

        Assert.Contains("do not nest", failure.Message, StringComparison.Ordinal);
        Assert.Equal(0, await adaptor.CountAsync(Criteria.For("Tag").Unbounded(), Token));
    }

    [Fact]
    public async Task RowsAndTheirLinksWrittenInOneTransactionAppearTogether()
    {
        var adaptor = Blog(out _);
        PendingId postPending = new("Post");
        PendingId commentPending = new("Comment");

        var ids = await adaptor.TransactionAsync(
            async token =>
            {
                var rows = await adaptor.WriteAsync(
                    new WriteBatch(
                        new Insert("Post", postPending, new Dictionary<string, object?> { ["title"] = "p" }),
                        new Insert("Comment", commentPending, new Dictionary<string, object?> { ["body"] = "c" })),
                    token);

                await adaptor.WriteAsync(
                    new WriteBatch(new Link("Post", "comments", rows.IdFor(postPending), rows.IdFor(commentPending))),
                    token);

                return (Post: rows.IdFor(postPending), Comment: rows.IdFor(commentPending));
            },
            Token);

        var comments = await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", ids.Post)), Token);

        Assert.Equal([ids.Comment], comments.Items.Select(record => record.Id));
    }

    [Fact]
    public async Task KeysetPagingWalksEqualSortValuesWithoutGapsOrRepeats()
    {
        var adaptor = Blog(out _);
        var expected = new List<EntityId>();

        for (var n = 0; n < 25; n++)
        {
            expected.Add(await Post(adaptor, $"p{n}", rank: n % 3));
        }

        var criteria = Criteria.For("Post").OrderBy(Order.Descending("rank"));
        var seen = new List<EntityId>();
        ICursor? cursor = null;

        do
        {
            var page = await adaptor.QueryAsync(criteria.Take(7, cursor), Token);
            seen.AddRange(page.Items.Select(record => record.Id));
            cursor = page.Next;

            Assert.True(cursor is null or KeysetCursor, "Cosmos never emits an offset");
        }
        while (cursor is not null);

        Assert.Equal(25, seen.Distinct().Count());

        // Highest rank first, and oldest first within a rank.
        var everything = (await adaptor.QueryAsync(criteria.Unbounded(), Token)).Items.Select(record => record.Id).ToList();
        Assert.Equal(everything, seen);
        Assert.Equal(expected.Where((_, n) => n % 3 == 2).Select(id => id), seen.Take(8));
    }

    [Fact]
    public async Task AnOffsetCursorIsStillAccepted()
    {
        var adaptor = Blog(out _);

        for (var n = 0; n < 5; n++)
        {
            await Post(adaptor, $"p{n}", rank: n);
        }

        var page = await adaptor.QueryAsync(Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(2, new OffsetCursor(2)), Token);

        Assert.Equal([2L, 1L], page.Items.Select(record => record.Value("rank")));
        Assert.IsType<KeysetCursor>(page.Next);
    }

    [Fact]
    public async Task AnOrderingNobodyDeclaredSaysSoInsteadOfLeakingTheServiceError()
    {
        var adaptor = Blog(out _);
        await Post(adaptor, "a");

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            adaptor.QueryAsync(Criteria.For("Post").OrderBy(Order.Ascending("views")).Take(5), Token));

        Assert.Contains("CosmosOrdering", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task FiltersBehaveAsTheMemoryDriverDoes()
    {
        var adaptor = Blog(out _);
        var a = await Post(adaptor, "Alpha beta", slug: "a");
        var b = await Post(adaptor, "Gamma", slug: null);

        async Task<List<EntityId>> Ids(Filter filter) =>
            [.. (await adaptor.QueryAsync(Criteria.For("Post").Where(filter), Token)).Items.Select(record => record.Id).Order(Comparer<EntityId>.Create((x, y) => string.CompareOrdinal(x.ToString(), y.ToString())))];

        Assert.Equal([a], await Ids(Filter.Contains("title", "beta")));
        Assert.Equal([a], await Ids(Filter.StartsWith("title", "Alpha")));
        Assert.Empty(await Ids(Filter.StartsWith("title", "alpha")));
        Assert.Equal([b], await Ids(Filter.IsNull("slug")));
        Assert.Equal([a], await Ids(Filter.IsNotNull("slug")));
        Assert.Equal([b], await Ids(Filter.NotEqual("slug", "a")));
        Assert.Equal([a, b], await Ids(Filter.In("title", ["Alpha beta", "Gamma"])));
        Assert.Equal([b], await Ids(Filter.NotIn("title", ["Alpha beta"])));
        Assert.Equal([a], await Ids(Filter.Equal("id", a.ToString())));
    }

    [Fact]
    public async Task ARowWithoutAValueStillAppearsInASortedList()
    {
        var adaptor = Blog(out _);
        await Insert(adaptor, "Post", new() { ["title"] = "no rank given" });
        await Post(adaptor, "ranked", rank: 5);

        var page = await adaptor.QueryAsync(Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(10), Token);

        Assert.Equal(2, page.Items.Count);
        Assert.Null(page.Items[1].Value("rank"));
    }

    [Fact]
    public async Task EverySortedEntityKeepsEveryFieldSoCosmosDoesNotDropItFromOrderBy()
    {
        var adaptor = Blog(out _);
        var id = await Insert(adaptor, "Post", new() { ["title"] = "only a title" });

        var record = await adaptor.GetAsync("Post", id, Token);

        Assert.NotNull(record);
        Assert.True(record!.Has("rank"));
        Assert.Null(record.Value("rank"));
    }

    // ----- edges in every shape ----------------------------------------------------------------------------

    [Fact]
    public async Task AManyToOneEdgeIsAReferenceOnTheDeclaringRowAndReadsBothWays()
    {
        var adaptor = Blog(out _);
        var p1 = await Post(adaptor, "p1");
        var p2 = await Post(adaptor, "p2");
        var author = await Insert(adaptor, "Author", new() { ["name"] = "ada" });
        var other = await Insert(adaptor, "Author", new() { ["name"] = "bob" });

        await adaptor.WriteAsync(new WriteBatch(new Link("Post", "author", p1, author), new Link("Post", "author", p2, author)), Token);

        var authors = await adaptor.QueryAsync(Criteria.For("Author").LinkedTo(EdgeFilter.Along("Post", "author", p1)), Token);
        var posts = await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "author", author)), Token);
        var none = await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "author", other)), Token);

        Assert.Equal([author], authors.Items.Select(record => record.Id));
        Assert.Equal(2, posts.Items.Count);
        Assert.Empty(none.Items);

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "author", p1, other)), Token);
        Assert.Single((await adaptor.QueryAsync(Criteria.For("Author").LinkedTo(EdgeFilter.Along("Post", "author", p1)), Token)).Items);

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "author", p1, author)), Token);
        Assert.Empty((await adaptor.QueryAsync(Criteria.For("Author").LinkedTo(EdgeFilter.Along("Post", "author", p1)), Token)).Items);
        Assert.Single((await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "author", author)), Token)).Items);
    }

    [Fact]
    public async Task AOneToManyEdgeIsAReferenceOnEachTargetAndUnlinkingTheOwnerClearsThemAll()
    {
        var adaptor = Blog(out _);
        var post = await Post(adaptor, "p");
        var other = await Post(adaptor, "q");
        var c1 = await Insert(adaptor, "Comment", new() { ["body"] = "1" });
        var c2 = await Insert(adaptor, "Comment", new() { ["body"] = "2" });

        await adaptor.WriteAsync(new WriteBatch(new Link("Post", "comments", post, c1), new Link("Post", "comments", post, c2)), Token);

        Assert.Equal(2, (await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", post)), Token)).Items.Count);
        Assert.Equal(
            [post],
            (await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "comments", c1)), Token)).Items.Select(record => record.Id));

        // Naming a different owner does not clear: the guard is that the comment still belongs to the owner named.
        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "comments", other, c1)), Token);
        Assert.Equal(2, (await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", post)), Token)).Items.Count);

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "comments", post)), Token);
        Assert.Empty((await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", post)), Token)).Items);
    }

    [Fact]
    public async Task ABatchOfParentsGetsOneRowPerParentInEveryShape()
    {
        var adaptor = Blog(out _);
        var p1 = await Post(adaptor, "p1");
        var p2 = await Post(adaptor, "p2");
        var c1 = await Insert(adaptor, "Comment", new() { ["body"] = "1" });
        var c2 = await Insert(adaptor, "Comment", new() { ["body"] = "2" });
        var t1 = await Insert(adaptor, "Tag", new() { ["label"] = "x" });
        var t2 = await Insert(adaptor, "Tag", new() { ["label"] = "y" });
        await adaptor.WriteAsync(
            new WriteBatch(
                new Link("Post", "comments", p1, c1),
                new Link("Post", "comments", p2, c2),
                new Link("Post", "tags", p1, t1),
                new Link("Post", "tags", p1, t2),
                new Link("Post", "tags", p2, t1)),
            Token);

        static IEnumerable<string> Pairs(IEnumerable<Record> rows) =>
            rows.Select(row => $"{row.Id}<-{row.Value(EdgeFilter.ParentColumn)}").Order();

        var comments = await adaptor.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", p1, p2)), Token);
        var tags = await adaptor.QueryAsync(Criteria.For("Tag").LinkedTo(EdgeFilter.Along("Post", "tags", p1, p2)), Token);
        var posts = await adaptor.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "tags", t1, t2)), Token);

        Assert.Equal(new[] { $"{c1}<-{p1}", $"{c2}<-{p2}" }.Order(), Pairs(comments.Items));
        Assert.Equal(new[] { $"{t1}<-{p1}", $"{t1}<-{p2}", $"{t2}<-{p1}" }.Order(), Pairs(tags.Items));
        Assert.Equal(new[] { $"{p1}<-{t1}", $"{p1}<-{t2}", $"{p2}<-{t1}" }.Order(), Pairs(posts.Items));

        // A page of expanded rows is cut and resumed in memory, still strictly after its position.
        var criteria = Criteria.For("Tag").LinkedTo(EdgeFilter.Along("Post", "tags", p1, p2));
        var first = await adaptor.QueryAsync(criteria.Take(2), Token);
        var second = await adaptor.QueryAsync(criteria.Take(2, first.Next), Token);

        Assert.Equal(2, first.Items.Count);
        Assert.Single(second.Items);
        Assert.Null(second.Next);
    }

    [Fact]
    public async Task LinkingTwiceIsOneLinkAndUnlinkingWhatIsNotLinkedIsQuiet()
    {
        var adaptor = Blog(out _);
        var post = await Post(adaptor, "p");
        var tag = await Insert(adaptor, "Tag", new() { ["label"] = "x" });

        await adaptor.WriteAsync(new WriteBatch(new Link("Post", "tags", post, tag), new Link("Post", "tags", post, tag)), Token);
        await adaptor.WriteAsync(new WriteBatch(new Link("Post", "tags", post, tag)), Token);

        Assert.Equal(1, await adaptor.CountAsync(Criteria.For("Tag").LinkedTo(EdgeFilter.Along("Post", "tags", post)), Token));

        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "tags", post, tag)), Token);
        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "tags", post, tag)), Token);
        await adaptor.WriteAsync(new WriteBatch(new Unlink("Post", "tags", post)), Token);
        await adaptor.WriteAsync(new WriteBatch(new Delete("Post", post), new Delete("Post", post)), Token);

        Assert.Null(await adaptor.GetAsync("Post", post, Token));
    }

    // ----- installing and moving data -----------------------------------------------------------------------

    [Fact]
    public async Task InstallingIsIdempotentOnAnEmptyContainerAndRefusesAPopulatedOne()
    {
        var environment = CosmosTestEnvironment.Require();
        var manifest = Layouts.Blog($"t-{Guid.NewGuid():N}", $"install-{Guid.NewGuid():N}");

        await CosmosInstaller.InstallAsync(environment.Client, environment.Database, manifest, cancellationToken: Token);
        await CosmosInstaller.InstallAsync(environment.Client, environment.Database, manifest, cancellationToken: Token);

        var adaptor = new CosmosAdaptor(environment.Client, environment.Database, manifest);
        await Post(adaptor, "first");

        var failure = await Assert.ThrowsAsync<ContainerNotEmptyException>(() =>
            CosmosInstaller.InstallAsync(environment.Client, environment.Database, manifest, cancellationToken: Token));

        Assert.Equal(manifest.Container, failure.Container);
        Assert.Contains("export it, create a new container and import", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task AnEmptyContainerWithOtherUniqueKeysIsReportedNotChanged()
    {
        var environment = CosmosTestEnvironment.Require();
        var name = $"keys-{Guid.NewGuid():N}";

        await CosmosInstaller.InstallAsync(environment.Client, environment.Database, Layouts.Blog("p", name), cancellationToken: Token);

        var other = new CosmosStorageManifest(
            "p",
            new Dictionary<string, CosmosEntityLayout> { ["Thing"] = new("Thing", new Dictionary<string, string> { ["a"] = "a" }) },
            container: name);
        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            CosmosInstaller.InstallAsync(environment.Client, environment.Database, other, cancellationToken: Token));

        Assert.Contains("cannot be changed in place", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task ExportThenImportIntoANewContainerRoundTripsEveryDocument()
    {
        var environment = CosmosTestEnvironment.Require();
        var partition = $"t-{Guid.NewGuid():N}";
        var source = environment.Adaptor(Layouts.Blog(partition));
        var post = await Post(source, "kept", slug: "kept", rank: 3);
        var tag = await Insert(source, "Tag", new() { ["label"] = "x" });
        var comment = await Insert(source, "Comment", new() { ["body"] = "c" });
        await source.WriteAsync(new WriteBatch(new Link("Post", "tags", post, tag), new Link("Post", "comments", post, comment)), Token);

        using var export = new MemoryStream();
        var written = await CosmosBackup.ExportAsync(
            environment.Client.GetContainer(environment.Database, Layouts.Blog(partition).Container),
            export,
            Token);

        Assert.True(written >= 4);

        var lines = Encoding.UTF8.GetString(export.ToArray()).Split('\n', StringSplitOptions.RemoveEmptyEntries);
        Assert.DoesNotContain(lines, line => line.Contains("\"_etag\"", StringComparison.Ordinal));

        var copyManifest = Layouts.Blog(partition, $"copy-{Guid.NewGuid():N}");
        await CosmosInstaller.InstallAsync(environment.Client, environment.Database, copyManifest, cancellationToken: Token);
        var copyContainer = environment.Client.GetContainer(environment.Database, copyManifest.Container);

        export.Position = 0;
        Assert.Equal(written, await CosmosBackup.ImportAsync(copyContainer, export, Token));

        var copy = new CosmosAdaptor(environment.Client, environment.Database, copyManifest);
        var restored = await copy.GetAsync("Post", post, Token);

        Assert.Equal("kept", restored!.Value("title"));
        Assert.Equal(3L, restored.Value("rank"));
        Assert.Equal([tag], (await copy.QueryAsync(Criteria.For("Tag").LinkedTo(EdgeFilter.Along("Post", "tags", post)), Token)).Items.Select(record => record.Id));
        Assert.Equal([comment], (await copy.QueryAsync(Criteria.For("Comment").LinkedTo(EdgeFilter.Along("Post", "comments", post)), Token)).Items.Select(record => record.Id));

        // The unique keys came across too: the restored slug is taken.
        await Assert.ThrowsAsync<CommitRejectedException>(() => Post(copy, "dup", slug: "kept"));

        // Importing again, over documents, is refused.
        export.Position = 0;
        await Assert.ThrowsAsync<ContainerNotEmptyException>(() => CosmosBackup.ImportAsync(copyContainer, export, Token));
    }
}
