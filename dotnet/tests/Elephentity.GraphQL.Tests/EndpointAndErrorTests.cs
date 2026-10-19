using System.Net;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using Elephentity.GraphQL.Http;
using Elephentity.GraphQL.Manifest;
using Elephentity.GraphQL.Relay;
using Elephentity.Runtime.Verification;
using GraphQL;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;

namespace Elephentity.GraphQL.Tests;

public sealed class EndpointAndErrorTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    private static async Task<IHost> Serve()
    {
        var inner = new Host();

        return await new HostBuilder()
            .ConfigureWebHost(web => web
                .UseTestServer()
                .ConfigureServices(services =>
                {
                    services.AddRouting();
                    services.AddSingleton(inner.Services.GetRequiredService<Elephentity.Runtime.Gateway.IEntityGateway>());
                    services.AddElephentityGraphQL(BookModel.Manifest());
                })
                .Configure(app => app.UseRouting().UseEndpoints(endpoints => endpoints.MapElephentityGraphQL("/graphql"))))
            .StartAsync(Token);
    }

    [Fact]
    public async Task APostedQueryIsAnsweredAsJson()
    {
        using var host = await Serve();
        using var client = host.GetTestClient();

        var response = await client.PostAsJsonAsync("/graphql", new { query = "{ __typename books { totalCount } }" }, Token);
        var body = JsonDocument.Parse(await response.Content.ReadAsStringAsync(Token)).RootElement;

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("application/json", response.Content.Headers.ContentType?.MediaType);
        Assert.Equal("RootQuery", body.GetProperty("data").GetProperty("__typename").GetString());
        Assert.Equal(0, body.GetProperty("data").GetProperty("books").GetProperty("totalCount").GetInt32());
    }

    [Fact]
    public async Task ARequestThatIsNotAQueryIsRefusedBeforeItRuns()
    {
        using var host = await Serve();
        using var client = host.GetTestClient();

        Assert.Equal(HttpStatusCode.MethodNotAllowed, (await client.GetAsync("/graphql?query={__typename}", Token)).StatusCode);
        Assert.Equal(HttpStatusCode.UnsupportedMediaType, (await client.PostAsync("/graphql", new StringContent("{}", Encoding.UTF8, "text/plain"), Token)).StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, (await client.PostAsync("/graphql", new StringContent("{not json", Encoding.UTF8, "application/json"), Token)).StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, (await client.PostAsync("/graphql", new StringContent("{}", Encoding.UTF8, "application/json"), Token)).StatusCode);
    }

    [Fact]
    public async Task AVariableThatDoesNotCoerceKeepsGraphQlsOwnCodeAndMessage()
    {
        var host = new Host();

        foreach (var value in new object[] { "abc", 99999999999L })
        {
            var result = await host.Run("query($n: Int) { books(first: $n) { totalCount } }", new { n = value });
            var error = result.GetProperty("errors")[0];

            Assert.NotEqual("INTERNAL", error.GetProperty("extensions").GetProperty("code").GetString());
            Assert.DoesNotContain("Internal server error", error.GetProperty("message").GetString(), StringComparison.Ordinal);
        }
    }

    [Fact]
    public void ARejectedCommitIsBadUserInputWithEveryViolation()
    {
        var rejected = new CommitRejectedException([
            new FieldViolation("Book", "title", new Violation("required", "A title is required.")),
            new FieldViolation("Book", "pages", new Violation("range", "Too many.")),
        ]);

        var info = new ElephentityErrorInfoProvider().GetInfo(new global::GraphQL.Execution.UnhandledError("x", rejected));
        var violations = Assert.IsType<List<Dictionary<string, object?>>>(info.Extensions!["violations"]);

        Assert.Equal("BAD_USER_INPUT", info.Extensions["code"]);
        Assert.Equal(2, violations.Count);
        Assert.Equal("Book.title", violations[0]["path"]);
        Assert.Equal("range", violations[1]["code"]);
    }

    [Fact]
    public async Task AnythingElseIsMaskedAsInternalAndLogged()
    {
        var manifest = new GraphQLManifest(
            objects: new Dictionary<string, ObjectTypeEntry>
            {
                ["Boom"] = new("Boom", "Boom", new Dictionary<string, FieldEntry>
                {
                    ["id"] = new("id", new("ID", NonNull: true), _ => "1"),
                    ["bad"] = new("bad", new("String"), _ => throw new InvalidOperationException("connection string is hunter2")),
                }, source => source is string),
            },
            roots: new Dictionary<string, RootFieldEntry> { ["Boom"] = new("Boom", "Booms", "Boom") });
        var logs = new List<string>();
        var request = new global::GraphQL.Transport.GraphQLRequest { Query = "{ boom(id: \"1\") { bad } }" };
        using var factory = new Microsoft.Extensions.Logging.LoggerFactory([new ListLogger(logs)]);
        var result = await new ElephentityGraphQLExecutor(
            Elephentity.GraphQL.Schema.ElephentitySchema.Build(manifest),
            new ElephentityGraphQLOptions(),
            Microsoft.Extensions.Logging.LoggerFactoryExtensions.CreateLogger<ElephentityGraphQLExecutor>(factory))
            .ExecuteToJsonAsync(request, new SingleService(new FakeGateway()), Token);

        Assert.Contains("Internal server error.", result, StringComparison.Ordinal);
        Assert.Contains("INTERNAL", result, StringComparison.Ordinal);
        Assert.DoesNotContain("hunter2", result, StringComparison.Ordinal);
        Assert.Contains(logs, line => line.Contains("hunter2", StringComparison.Ordinal));
    }

    [Fact]
    public async Task AFieldThatNeedsIoIsReadAsynchronously()
    {
        var manifest = new GraphQLManifest(
            objects: new Dictionary<string, ObjectTypeEntry>
            {
                ["Note"] = new("Note", "Note", new Dictionary<string, FieldEntry>
                {
                    ["id"] = new("id", new("ID", NonNull: true), _ => "1"),
                    ["author"] = new("author", new("String"), null, ReadAsync: async (_, token) =>
                    {
                        await Task.Yield();

                        return "Ada";
                    }),
                }, source => source is string),
            },
            roots: new Dictionary<string, RootFieldEntry> { ["Note"] = new("Note", "Notes", "Note") });
        var result = await new ElephentityGraphQLExecutor(Elephentity.GraphQL.Schema.ElephentitySchema.Build(manifest), new ElephentityGraphQLOptions())
            .ExecuteToJsonAsync(new global::GraphQL.Transport.GraphQLRequest { Query = "{ note(id: \"1\") { author } }" }, new SingleService(new FakeGateway()), Token);

        Assert.Contains("\"author\":\"Ada\"", result, StringComparison.Ordinal);
    }

    [Fact]
    public void GlobalIdsKeepTheEncodingTheTypeScriptClientAlreadyHolds()
    {
        Assert.Equal("ZWxlcGg6Qm9vazoxMg==", GlobalId.Encode("Book", "12"));
        Assert.Equal(new GlobalId("Book", "12"), GlobalId.Decode("ZWxlcGg6Qm9vazoxMg=="));
        Assert.Equal(new GlobalId("Book", "a:b"), GlobalId.Decode(GlobalId.Encode("Book", "a:b")));
        Assert.Null(GlobalId.Decode("12"));
        Assert.Null(GlobalId.Decode("!!!"));
        Assert.Null(GlobalId.Decode(Convert.ToBase64String(Encoding.UTF8.GetBytes("other:Book:1"))));
        Assert.Null(GlobalId.Decode(Convert.ToBase64String(Encoding.UTF8.GetBytes("eleph:Book:"))));
        Assert.Equal("12", GlobalId.Raw("ZWxlcGg6Qm9vazoxMg==", "Book"));
        Assert.Equal("12", GlobalId.Raw("12", "Book"));
        Assert.Throws<GraphQLInputException>(() => GlobalId.Raw("ZWxlcGg6Qm9vazoxMg==", "Author"));
        Assert.Null(GlobalId.EntityIdOf(null));
    }

    private sealed class SingleService(Elephentity.Runtime.Gateway.IEntityGateway gateway) : IServiceProvider
    {
        public object? GetService(Type serviceType) => serviceType == typeof(Elephentity.Runtime.Gateway.IEntityGateway) ? gateway : null;
    }

    private sealed class FakeGateway : Elephentity.Runtime.Gateway.IEntityGateway
    {
        public ValueTask<object?> FindAsync(string entity, Elephentity.Runtime.Identity.EntityId id, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult<object?>("row");

        public Elephentity.Runtime.Query.IEntityQuery<object> All(string entity) => throw new NotSupportedException();

        public ValueTask<object?> RunQueryAsync(string entity, string query, IReadOnlyDictionary<string, object?> args, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<Elephentity.Runtime.Mutation.MutationResult> CreateAsync(string entity, IReadOnlyDictionary<string, object?> input, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<Elephentity.Runtime.Mutation.MutationResult> UpdateAsync(string entity, Elephentity.Runtime.Identity.EntityId id, IReadOnlyDictionary<string, object?> input, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task DeleteAsync(string entity, Elephentity.Runtime.Identity.EntityId id, CancellationToken cancellationToken = default) => throw new NotSupportedException();

        public Task<Elephentity.Runtime.Mutation.MutationResult> RunActionAsync(string entity, string action, Elephentity.Runtime.Identity.EntityId id, IReadOnlyDictionary<string, object?> args, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<Elephentity.Runtime.Mutation.MutationResult> RunActionsAsync(string entity, Elephentity.Runtime.Identity.EntityId id, IReadOnlyList<Elephentity.Runtime.Mutation.ActionCall> actions, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }

    private sealed class ListLogger(List<string> lines) : Microsoft.Extensions.Logging.ILoggerProvider, Microsoft.Extensions.Logging.ILogger
    {
        public Microsoft.Extensions.Logging.ILogger CreateLogger(string categoryName) => this;

        public IDisposable? BeginScope<TState>(TState state)
            where TState : notnull => null;

        public bool IsEnabled(Microsoft.Extensions.Logging.LogLevel logLevel) => true;

        public void Log<TState>(Microsoft.Extensions.Logging.LogLevel logLevel, Microsoft.Extensions.Logging.EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter) =>
            lines.Add(formatter(state, exception) + " " + exception);

        public void Dispose()
        {
        }
    }
}
