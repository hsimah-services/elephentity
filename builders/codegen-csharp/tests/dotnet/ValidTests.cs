using Elephentity.Runtime.Catalogue;
using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Memory;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Testing;
using Elephentity.Runtime.Verification;
using Golden.Valid;
using Golden.Valid.Contract;
using Golden.Valid.Declared;
using Golden.Valid.Enums;
using Golden.Valid.Stubs;
using Golden.Valid.Types;
using Microsoft.Extensions.DependencyInjection;
using GatewayRuntime = Elephentity.Runtime.Gateway.Runtime;

namespace Golden.Tests;

/// <summary>
/// The canonical spec's generated tree, wired with stub contracts and run on the memory driver: create, read, update, delete,
/// an action, a gated read, a paged query, the verifiers and side effects, and the contract check.
/// </summary>
public sealed class ValidTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    private sealed class Host
    {
        public Host()
        {
            var services = new ServiceCollection();

            services.AddSingleton<IStorageAdaptor>(Storage);
            services.AddSingleton(Calls);
            services.AddSingleton<IMoneyReadProcessor, MoneyReader>();
            services.AddSingleton<IMoneyWriteProcessor, MoneyWriter>();
            services.AddSingleton<IPostPublishAction, PublishAction>();
            services.AddSingleton<IPostPublishedQuery, PublishedQuery>();
            services.AddSingleton<IPostAuditSideEffect, AuditEffect>();
            services.AddSingleton<IPostReindexSideEffect, ReindexEffect>();
            services.AddSingleton<IPostPriceVerifier, PriceVerifier>();
            services.AddSingleton<Golden.Valid.Pattern.Contract.IAuditableOwnerReadPolicy, OwnerRead>();
            services.AddSingleton<IPostReporterReadPolicy, ReporterRead>();
            services.AddSingleton<IPostNoBackwardsWritePolicy, NoBackwardsWrite>();
            services.AddFixtureEntities();

            Provider = services.BuildServiceProvider();
        }

        public MemoryAdaptor Storage { get; } = new();

        public Calls Calls { get; } = new();

        public ServiceProvider Provider { get; }

        public IEntityGateway Gateway => Provider.GetRequiredService<IEntityGateway>();
    }

    private static Dictionary<string, object?> Post(string title, string status = "draft", string slug = "p") => new()
    {
        ["title"] = title,
        ["status"] = status,
        ["slug"] = slug,
        ["visibility"] = "public",
    };

    [Fact]
    public async Task ACreatedPostComesBackTypedWithItsManagedFieldsStamped()
    {
        var host = new Host();

        var result = await host.Gateway.CreateAsync("Post", Post("Hello"), Token);
        var post = Assert.IsType<Golden.Valid.Post>(result.Entity);

        Assert.Equal("Hello", post.Title);
        Assert.Equal(PostStatus.Draft, post.Status);
        Assert.Equal(PostVisibility.Public, post.Visibility);
        Assert.Equal(post.CreatedAt, post.UpdatedAt);
        Assert.True(post.CreatedAt > DateTimeOffset.UtcNow.AddMinutes(-1));

        // Stored as the wire values, not the C# names.
        var stored = await host.Storage.GetAsync("Post", result.Id, Token);
        Assert.Equal("draft", stored!.Value("status"));
        Assert.Equal("public", stored.Value("visibility"));
    }

    [Fact]
    public async Task APostCanBeFoundUpdatedAndDeleted()
    {
        var host = new Host();
        var created = await host.Gateway.CreateAsync("Post", Post("Hello"), Token);

        var found = Assert.IsType<Golden.Valid.Post>(await host.Gateway.FindAsync("Post", created.Id, Token));
        var updated = await host.Gateway.UpdateAsync("Post", created.Id, new Dictionary<string, object?> { ["title"] = "Renamed" }, Token);

        Assert.Equal("Hello", found.Title);
        Assert.Equal("Renamed", Assert.IsType<Golden.Valid.Post>(updated.Entity).Title);
        Assert.True(((Golden.Valid.Post)updated.Entity!).UpdatedAt >= found.UpdatedAt);

        await host.Gateway.DeleteAsync("Post", created.Id, Token);

        Assert.Null(await host.Gateway.FindAsync("Post", created.Id, Token));
    }

    [Fact]
    public async Task ARequiredFieldMissingFromACreateIsRejectedByName()
    {
        var host = new Host();

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() =>
            host.Gateway.CreateAsync("Post", new Dictionary<string, object?> { ["slug"] = "x", ["status"] = "draft" }, Token));

        Assert.Equal(["Post.title", "Post.visibility"], rejected.Paths());
    }

    [Fact]
    public async Task ADeclaredTypeIsReadAndWrittenThroughItsProcessors()
    {
        var host = new Host();

        var created = await host.Gateway.CreateAsync("Post", Post("Priced"), Token);
        var priced = await host.Gateway.UpdateAsync("Post", created.Id, new Dictionary<string, object?> { ["price"] = 250L }, Token);

        Assert.Equal(new Money(250), ((Golden.Valid.Post)priced.Entity!).Price);
        Assert.Equal(250L, (await host.Storage.GetAsync("Post", created.Id, Token))!.Value("price"));

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() =>
            host.Gateway.UpdateAsync("Post", created.Id, new Dictionary<string, object?> { ["price"] = -5L }, Token));

        Assert.Equal("money.negative", rejected.Violations[0].Violation.Code);
    }

    [Fact]
    public async Task AFieldVerifierRunsAndBothTiersReportTogether()
    {
        var host = new Host();
        var created = await host.Gateway.CreateAsync("Post", Post("p"), Token);

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() =>
            host.Gateway.UpdateAsync("Post", created.Id, new Dictionary<string, object?> { ["price"] = 5_000_000L }, Token));

        Assert.Equal(["price.too-high"], rejected.Violations.Select(v => v.Violation.Code));
    }

    [Fact]
    public async Task AnActionRunsThroughTheMutatorAndWritesOnlyWhatItDeclares()
    {
        var host = new Host();
        var created = await host.Gateway.CreateAsync("Post", Post("Soon"), Token);

        var result = await host.Gateway.RunActionAsync(
            "Post",
            "publish",
            created.Id,
            new Dictionary<string, object?> { ["at"] = "2026-01-01T00:00:00Z" },
            Token);

        Assert.Equal(PostStatus.Published, ((Golden.Valid.Post)result.Entity!).Status);
    }

    [Fact]
    public async Task AWritePolicyCanRefuseAChange()
    {
        var host = new Host();
        var created = await host.Gateway.CreateAsync("Post", Post("Live", "published"), Token);

        var denied = await Assert.ThrowsAnyAsync<Exception>(() =>
            host.Gateway.UpdateAsync("Post", created.Id, new Dictionary<string, object?> { ["status"] = "draft" }, Token));

        Assert.IsAssignableFrom<AccessDeniedException>(denied);
        Assert.Equal(
            PostStatus.Published,
            ((Golden.Valid.Post)(await host.Gateway.FindAsync("Post", created.Id, Token))!).Status);
    }

    [Fact]
    public async Task AReadPolicyHidesRowsFromListsAndPagesAcrossTheHiddenOnes()
    {
        var host = new Host();

        for (var n = 0; n < 7; n++)
        {
            await host.Gateway.CreateAsync("Post", Post(n % 2 == 0 ? "secret" : $"open {n}", slug: $"s{n}"), Token);
        }

        var all = await host.Gateway.All("Post").AllAsync(Token);
        Assert.Equal(3, all.Count);
        Assert.Equal(3, await host.Gateway.All("Post").CountAsync(Token));

        var seen = new List<string>();
        ICursor? cursor = null;

        do
        {
            var page = await host.Gateway.All("Post").PageAsync(2, cursor, Token);
            seen.AddRange(page.Items.Cast<Golden.Valid.Post>().Select(post => post.Title));
            cursor = page.Next;
        }
        while (cursor is not null);

        Assert.Equal(["open 1", "open 3", "open 5"], seen);
    }

    [Fact]
    public async Task ADeclaredQueryRunsByNameAndReturnsTypedRows()
    {
        var host = new Host();
        await host.Gateway.CreateAsync("Post", Post("draft one", slug: "a"), Token);
        await host.Gateway.CreateAsync("Post", Post("live one", "published", slug: "b"), Token);

        var result = await host.Gateway.RunQueryAsync("Post", "published", new Dictionary<string, object?> { ["limit"] = 5L }, Token);
        // The gateway's untyped surface: a collection comes back as rows of object, as `All` does, so GraphQL can page it.
        var posts = await Assert.IsAssignableFrom<IEntityQuery<object>>(result).AllAsync(Token);

        Assert.Equal(["live one"], posts.Cast<Golden.Valid.Post>().Select(post => post.Title));
    }

    [Fact]
    public async Task SideEffectsRunAfterTheCommitInTheOrderTheSpecDeclaresThem()
    {
        var host = new Host();

        await host.Gateway.CreateAsync("Post", Post("Hello"), Token);

        Assert.Equal(["audit:create:Hello", "reindex"], host.Calls.Seen);
    }

    [Fact]
    public async Task EdgesWorkInEveryDirectionAndLinksArePersistedWithTheRow()
    {
        var host = new Host();
        var author = await host.Gateway.CreateAsync("Author", new Dictionary<string, object?> { ["name"] = "Ada" }, Token);
        var comment = await host.Gateway.CreateAsync(
            "Comment",
            new Dictionary<string, object?> { ["body"] = "Nice", ["author"] = author.Id.ToString() },
            Token);
        var tag = await host.Gateway.CreateAsync("Tag", new Dictionary<string, object?> { ["label"] = "news" }, Token);
        var post = await host.Gateway.CreateAsync(
            "Post",
            Post("Tagged"),
            Token);
        await host.Gateway.UpdateAsync(
            "Post",
            post.Id,
            new Dictionary<string, object?> { ["tags"] = new List<object?> { tag.Id.ToString() }, ["comments"] = new List<object?> { comment.Id.ToString() } },
            Token);

        var typedPost = (Golden.Valid.Post)(await host.Gateway.FindAsync("Post", post.Id, Token))!;
        var typedComment = (Comment)(await host.Gateway.FindAsync("Comment", comment.Id, Token))!;
        var typedAuthor = (Author)(await host.Gateway.FindAsync("Author", author.Id, Token))!;

        Assert.Equal(["news"], (await typedPost.Tags().AllAsync(Token)).Select(t => t.Label));
        Assert.Equal(["Nice"], (await typedPost.Comments().AllAsync(Token)).Select(c => c.Body));
        Assert.Equal("Ada", (await typedComment.GetAuthorAsync(Token))!.Name);
        Assert.Equal(["Nice"], (await typedAuthor.Comments().AllAsync(Token)).Select(c => c.Body));
        Assert.Equal(["Tagged"], (await (await host.Gateway.FindAsync("Tag", tag.Id, Token) is Tag t ? t.Posts().AllAsync(Token) : throw new InvalidOperationException())).Select(p => p.Title));
    }

    [Fact]
    public async Task AUniqueFieldIsRejectedBeforeItReachesStorage()
    {
        var host = new Host();
        await host.Gateway.CreateAsync("Tag", new Dictionary<string, object?> { ["label"] = "dup" }, Token);

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() =>
            host.Gateway.CreateAsync("Tag", new Dictionary<string, object?> { ["label"] = "dup" }, Token));

        Assert.Equal(["Tag.label"], rejected.Paths());
    }

    [Fact]
    public void EveryGeneratedContractIsBoundAndTheContractCheckSaysSo()
    {
        var host = new Host();
        var catalogue = host.Provider.GetRequiredService<IEntityCatalogue>();

        new ContractCheck(catalogue, host.Provider).AssertConforms();
        new Elephentity.Runtime.Catalogue.BootCheck(catalogue, host.Provider).Run();
    }

    [Fact]
    public void AMissingContractIsNamedByTheContractCheck()
    {
        var services = new ServiceCollection();
        services.AddSingleton<IStorageAdaptor>(new MemoryAdaptor());
        services.AddFixtureEntities();
        var provider = services.BuildServiceProvider();

        var failure = Assert.Throws<ContractCheckException>(
            new ContractCheck(provider.GetRequiredService<IEntityCatalogue>(), provider).AssertConforms);

        Assert.Contains(typeof(IPostPublishAction).FullName!, failure.Message, StringComparison.Ordinal);
        Assert.Contains(typeof(IMoneyReadProcessor).FullName!, failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void TheClassMapIndexesTheTree()
    {
        Assert.Equal("Golden.Valid.Post", ClassMap.Entities["Post"]);
        Assert.Equal("Post/PostHydrator.cs", ClassMap.Classes["Golden.Valid.PostHydrator"]);
    }
}
