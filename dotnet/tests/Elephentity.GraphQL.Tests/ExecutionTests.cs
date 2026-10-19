using System.Text.Json;
using Elephentity.GraphQL.Relay;

namespace Elephentity.GraphQL.Tests;

public sealed class ExecutionTests
{
    private static JsonElement Data(JsonElement result) => result.GetProperty("data");

    private static JsonElement FirstError(JsonElement result) => result.GetProperty("errors")[0];

    private static string Code(JsonElement result) => FirstError(result).GetProperty("extensions").GetProperty("code").GetString()!;

    private static async Task<string> CreateBook(Host host, string title, string status = "DRAFT", string? authors = null)
    {
        var result = await host.Run(
            "mutation($t: String!, $a: [ID]) { createBook(input: { title: $t, status: " + status + ", authors: $a }) { book { id } } }",
            new { t = title, a = authors is null ? Array.Empty<string>() : [authors] });

        return Data(result).GetProperty("createBook").GetProperty("book").GetProperty("id").GetString()!;
    }

    [Fact]
    public async Task ABookIsCreatedWithItsClientMutationIdAndAGlobalId()
    {
        var host = new Host();

        var result = await host.Run("mutation { createBook(input: { title: \"First\", status: DRAFT, clientMutationId: \"m1\" }) { clientMutationId book { id databaseId title status inPrint } } }");
        var payload = Data(result).GetProperty("createBook");
        var book = payload.GetProperty("book");

        Assert.Equal("m1", payload.GetProperty("clientMutationId").GetString());
        Assert.Equal(new GlobalId("Book", "1"), GlobalId.Decode(book.GetProperty("id").GetString()!));
        Assert.Equal("1", book.GetProperty("databaseId").GetString());
        Assert.Equal("DRAFT", book.GetProperty("status").GetString());
        Assert.Equal("First", book.GetProperty("title").GetString());
    }

    [Fact]
    public async Task ConnectionsPageForwardWithTheEndCursorAndCountOnlyWhenAsked()
    {
        var host = new Host();

        for (var n = 1; n <= 5; n++)
        {
            await CreateBook(host, $"b{n}");
        }

        var seen = new List<string>();
        string? after = null;
        var pages = 0;

        do
        {
            var result = await host.Run(
                "query($after: String) { books(first: 2, after: $after) { totalCount nodes { title } pageInfo { hasNextPage hasPreviousPage endCursor } } }",
                new { after });
            var books = Data(result).GetProperty("books");

            seen.AddRange(books.GetProperty("nodes").EnumerateArray().Select(node => node.GetProperty("title").GetString()!));
            Assert.Equal(5, books.GetProperty("totalCount").GetInt32());
            Assert.Equal(after is not null, books.GetProperty("pageInfo").GetProperty("hasPreviousPage").GetBoolean());

            var info = books.GetProperty("pageInfo");

            after = info.GetProperty("hasNextPage").GetBoolean() ? info.GetProperty("endCursor").GetString() : null;
            pages++;
        }
        while (after is not null);

        Assert.Equal(["b1", "b2", "b3", "b4", "b5"], seen);
        Assert.Equal(3, pages);
    }

    [Fact]
    public async Task PageSizesDefaultAndCapAndFirstZeroReturnsNothing()
    {
        var host = new Host(new ElephentityGraphQLOptions { DefaultPageSize = 2, MaxPageSize = 3 });

        for (var n = 1; n <= 5; n++)
        {
            await CreateBook(host, $"b{n}");
        }

        int Count(JsonElement result) => Data(result).GetProperty("books").GetProperty("nodes").GetArrayLength();

        Assert.Equal(2, Count(await host.Run("{ books { nodes { title } } }")));
        Assert.Equal(3, Count(await host.Run("{ books(first: 1000) { nodes { title } } }")));

        var zero = Data(await host.Run("{ books(first: 0) { nodes { title } edges { cursor } pageInfo { hasNextPage endCursor } } }")).GetProperty("books");

        Assert.Equal(0, zero.GetProperty("nodes").GetArrayLength());
        Assert.True(zero.GetProperty("pageInfo").GetProperty("hasNextPage").GetBoolean());
        Assert.Equal(JsonValueKind.Null, zero.GetProperty("pageInfo").GetProperty("endCursor").ValueKind);
    }

    [Theory]
    [InlineData("{ books(first: -1) { nodes { title } } }", "first must be non-negative.")]
    [InlineData("{ books(last: 1) { nodes { title } } }", "Backward pagination is not supported. Use first and after.")]
    [InlineData("{ books(before: \"x\") { nodes { title } } }", "Backward pagination is not supported. Use first and after.")]
    [InlineData("{ books(after: \"not a cursor\") { nodes { title } } }", "Invalid pagination cursor.")]
    public async Task PagingMistakesAreExplicitUserErrors(string query, string message)
    {
        var result = await new Host().Run(query);

        Assert.Equal("BAD_USER_INPUT", Code(result));
        Assert.Equal(message, FirstError(result).GetProperty("message").GetString());
    }

    [Fact]
    public async Task AnIdOfTheWrongTypeIsAnErrorAndNeverResolvesTheWrongRow()
    {
        var host = new Host();
        var book = await CreateBook(host, "Right");
        var author = Data(await host.Run("mutation { createAuthor(input: { name: \"A\" }) { author { id } } }")).GetProperty("createAuthor").GetProperty("author").GetProperty("id").GetString()!;

        var asAuthor = await host.Run("query($id: ID!) { author(id: $id) { name } }", new { id = book });
        var update = await host.Run("mutation($id: ID!) { updateBook(input: { id: $id, title: \"Hijacked\" }) { book { title } } }", new { id = author });
        var delete = await host.Run("mutation($id: ID!) { deleteBook(input: { id: $id }) { deletedId } }", new { id = author });

        Assert.Equal("Expected a Author ID, received Book.", FirstError(asAuthor).GetProperty("message").GetString());
        Assert.Equal("BAD_USER_INPUT", Code(update));
        Assert.Equal("BAD_USER_INPUT", Code(delete));
        Assert.Equal("Right", Data(await host.Run("query($id: ID!) { book(id: $id) { title } }", new { id = book })).GetProperty("book").GetProperty("title").GetString());
    }

    [Fact]
    public async Task ARawRowIdStillWorksAndDeleteReturnsTheGlobalIdToEvict()
    {
        var host = new Host();
        var global = await CreateBook(host, "Doomed");

        Assert.Equal("Doomed", Data(await host.Run("{ book(id: \"1\") { title } }")).GetProperty("book").GetProperty("title").GetString());

        var deleted = Data(await host.Run("mutation { deleteBook(input: { id: \"1\", clientMutationId: \"d\" }) { deletedId clientMutationId } }")).GetProperty("deleteBook");

        Assert.Equal(global, deleted.GetProperty("deletedId").GetString());
        Assert.Equal("d", deleted.GetProperty("clientMutationId").GetString());
        Assert.Equal(JsonValueKind.Null, Data(await host.Run("{ book(id: \"1\") { title } }")).GetProperty("book").ValueKind);
    }

    [Fact]
    public async Task NodeResolvesAnyTypeByGlobalIdAndRefusesWhatItDoesNotKnow()
    {
        var host = new Host();
        var book = await CreateBook(host, "Findable");

        var found = Data(await host.Run("query($id: ID!) { node(id: $id) { id ... on Book { title } } }", new { id = book })).GetProperty("node");
        var unknown = await host.Run("query($id: ID!) { node(id: $id) { id } }", new { id = GlobalId.Encode("Nothing", "1") });
        var raw = await host.Run("{ node(id: \"1\") { id } }");

        Assert.Equal("Findable", found.GetProperty("title").GetString());
        Assert.Equal(book, found.GetProperty("id").GetString());
        Assert.Equal("Invalid node ID.", FirstError(unknown).GetProperty("message").GetString());
        Assert.Equal("Invalid node ID.", FirstError(raw).GetProperty("message").GetString());
    }

    [Fact]
    public async Task ANodeThePolicyDeniesIsForbiddenAndLeaksNothing()
    {
        var host = new Host();

        // Written underneath the gateway: creating it through GraphQL would be refused for the same reason.
        var written = await host.Storage.WriteAsync(
            new Elephentity.Runtime.Storage.Write.WriteBatch(new Elephentity.Runtime.Storage.Write.Insert(
                "Book",
                new Elephentity.Runtime.Identity.PendingId("Book"),
                new Dictionary<string, object?> { ["title"] = "secret", ["status"] = "draft" })),
            TestContext.Current.CancellationToken);
        var secret = GlobalId.Encode("Book", "1");
        _ = written;

        var result = await host.Run("query($id: ID!) { node(id: $id) { id } book(id: $id) { title } }", new { id = secret });

        Assert.Equal("FORBIDDEN", Code(result));
        Assert.Equal("Secret books are hidden.", FirstError(result).GetProperty("message").GetString());
        Assert.Equal(JsonValueKind.Null, Data(result).GetProperty("node").ValueKind);
        Assert.DoesNotContain("\"secret\"", result.GetRawText().Replace("Secret books", string.Empty, StringComparison.Ordinal), StringComparison.Ordinal);
    }

    [Fact]
    public async Task UpdatingARowThatIsNotThereIsNotFound()
    {
        var result = await new Host().Run("mutation { updateBook(input: { id: \"99\", title: \"x\" }) { book { title } } }");

        Assert.Equal("NOT_FOUND", Code(result));
    }

    [Fact]
    public async Task EdgesAreConnectionsInBothDirectionsAndLinksAreWrittenFromGlobalIds()
    {
        var host = new Host();
        var author = Data(await host.Run("mutation { createAuthor(input: { name: \"Ada\" }) { author { id } } }")).GetProperty("createAuthor").GetProperty("author").GetProperty("id").GetString()!;

        var book = await CreateBook(host, "Linked", authors: author);

        var forward = Data(await host.Run("query($id: ID!) { book(id: $id) { authors { totalCount nodes { name } } } }", new { id = book })).GetProperty("book").GetProperty("authors");
        var inverse = Data(await host.Run("query($id: ID!) { author(id: $id) { books { nodes { title } } } }", new { id = author })).GetProperty("author").GetProperty("books");

        Assert.Equal(1, forward.GetProperty("totalCount").GetInt32());
        Assert.Equal("Ada", forward.GetProperty("nodes")[0].GetProperty("name").GetString());
        Assert.Equal("Linked", inverse.GetProperty("nodes")[0].GetProperty("title").GetString());
    }

    [Fact]
    public async Task AnActionRunsAsAMutationAndAPublishedQueryFiltersByItsArguments()
    {
        var host = new Host();
        var id = await CreateBook(host, "Soon");

        await CreateBook(host, "Other");

        var published = Data(await host.Run("mutation($id: ID!) { publishBook(input: { id: $id }) { book { status } } }", new { id })).GetProperty("publishBook");
        var live = Data(await host.Run("{ searchBooks(where: { status: LIVE }) { totalCount nodes { title } } bookBySlug(slug: \"Other\") { title } }"));

        Assert.Equal("LIVE", published.GetProperty("book").GetProperty("status").GetString());
        Assert.Equal(1, live.GetProperty("searchBooks").GetProperty("totalCount").GetInt32());
        Assert.Equal("Soon", live.GetProperty("searchBooks").GetProperty("nodes")[0].GetProperty("title").GetString());
        Assert.Equal("Other", live.GetProperty("bookBySlug").GetProperty("title").GetString());
    }

    [Fact]
    public async Task DatesAndJsonTravelAsStrings()
    {
        var host = new Host();

        await host.Run("mutation { createBook(input: { title: \"d\", status: DRAFT, publishedAt: \"2026-01-02T03:04:05+00:00\" }) { book { id } } }");

        var book = Data(await host.Run("{ book(id: \"1\") { publishedAt tags } }")).GetProperty("book");

        Assert.Equal("2026-01-02T03:04:05+00:00", book.GetProperty("publishedAt").GetString());
        Assert.Equal(JsonValueKind.Null, book.GetProperty("tags").ValueKind);
    }

    [Fact]
    public async Task DepthLimitsAndDisabledIntrospectionAreEnforced()
    {
        var limited = await new Host(new ElephentityGraphQLOptions { MaxDepth = 2 }).Run("{ books { nodes { authors { nodes { name } } } } }");
        var hidden = await new Host(new ElephentityGraphQLOptions { EnableIntrospection = false }).Run("{ __schema { queryType { name } } }");
        var open = await new Host().Run("{ __schema { queryType { name } } }");

        Assert.True(limited.TryGetProperty("errors", out _));
        Assert.True(hidden.TryGetProperty("errors", out _));
        Assert.Equal("RootQuery", Data(open).GetProperty("__schema").GetProperty("queryType").GetProperty("name").GetString());
    }
}
