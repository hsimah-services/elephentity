using System.Net;
using System.Text.Json;
using System.Text.RegularExpressions;
using Elephentity.GraphQL.Http;
using Elephentity.GraphQL.Manifest;
using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Query;
using Elephentity.Tsquid.Http;
using Elephentity.Tsquid.Manifest;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;

namespace Elephentity.Tsquid.Tests;

/// <summary>The middleware over a real pipeline: routing a navigation, running the query as the requesting viewer, and staying out of the way.</summary>
public sealed partial class MiddlewareTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    private const string Shell = "<html><head></head><body><script type=\"module\" src=\"/assets/app.js\"></script></body></html>";

    internal sealed record Viewer(string Id, string Name);

    /// <summary>A gateway for one request: the viewer is whoever the request says it is, which is what the middleware has to preserve.</summary>
    internal sealed class RequestGateway(IHttpContextAccessor http) : IEntityGateway
    {
        public static List<IReadOnlyDictionary<string, object?>> Calls { get; } = [];

        public async ValueTask<object?> RunQueryAsync(string entity, string query, IReadOnlyDictionary<string, object?> args, CancellationToken cancellationToken = default)
        {
            Calls.Add(args);

            if (http.HttpContext!.Request.Headers.ContainsKey("X-Slow"))
            {
                await Task.Delay(Timeout.Infinite, cancellationToken);
            }

            var name = http.HttpContext!.Request.Headers["X-Viewer"].ToString();

            return new Viewer(name, name);
        }

        public ValueTask<object?> FindAsync(string entity, EntityId id, CancellationToken cancellationToken = default) => throw new NotSupportedException();

        public IEntityQuery<object> All(string entity) => throw new NotSupportedException();

        public Task<MutationResult> CreateAsync(string entity, IReadOnlyDictionary<string, object?> input, CancellationToken cancellationToken = default) => throw new NotSupportedException();

        public Task<MutationResult> UpdateAsync(string entity, EntityId id, IReadOnlyDictionary<string, object?> input, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task DeleteAsync(string entity, EntityId id, CancellationToken cancellationToken = default) => throw new NotSupportedException();

        public Task<MutationResult> RunActionAsync(string entity, string action, EntityId id, IReadOnlyDictionary<string, object?> args, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<MutationResult> RunActionsAsync(string entity, EntityId id, IReadOnlyList<ActionCall> actions, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }

    internal static GraphQLManifest Graph() => new(
        objects: new Dictionary<string, ObjectTypeEntry>
        {
            ["Viewer"] = new(
                "Viewer",
                "Viewer",
                new Dictionary<string, FieldEntry>
                {
                    ["id"] = new("id", new("ID", NonNull: true), source => ((Viewer)source).Id),
                    ["name"] = new("name", new("String", NonNull: true), source => ((Viewer)source).Name),
                },
                source => source is Viewer),
        },
        queries: new Dictionary<string, QueryFieldEntry>
        {
            ["viewer"] = new(
                "viewer",
                "Viewer",
                false,
                "Viewer",
                "current",
                new Dictionary<string, GraphQLType> { ["name"] = new("String") }),
        });

    private static RouteManifest Routes() => new([
        new TsquidRoute("Home", "/", true, new Dictionary<string, RouteField>()),
        new TsquidRoute(
            "Profile",
            "/me",
            true,
            new Dictionary<string, RouteField>(),
            new RouteQuery("ProfileQuery", "p1", "query ProfileQuery {\n  viewer {\n    id\n    name\n  }\n}\n", new Dictionary<string, VariableBinding>())),
        new TsquidRoute(
            "Named",
            "/named/:name",
            true,
            new Dictionary<string, RouteField> { ["name"] = new(FieldKind.Text, false, false, FieldLocation.Path) },
            new RouteQuery(
                "NamedQuery",
                "n1",
                "query NamedQuery($name: String) { viewer(name: $name) { id name } }",
                new Dictionary<string, VariableBinding> { ["name"] = new("name", false, null) })),
    ]);

    private static async Task<IHost> Serve(Func<HttpContext, ValueTask<string?>>? shell = null, TimeSpan? timeout = null) => await new HostBuilder()
        .ConfigureWebHost(web => web
            .UseTestServer()
            .ConfigureServices(services =>
            {
                services.AddHttpContextAccessor();
                services.AddScoped<IEntityGateway, RequestGateway>();
                services.AddElephentityGraphQL(Graph());
            })
            .Configure(app =>
            {
                // TestServer does not set the raw target the way Kestrel does; the test says what the client wrote.
                app.Use((http, next) =>
                {
                    if (http.Request.Headers["X-Raw-Target"].ToString() is { Length: > 0 } raw)
                    {
                        http.Features.Get<Microsoft.AspNetCore.Http.Features.IHttpRequestFeature>()!.RawTarget = raw;
                    }

                    return next(http);
                });
                app.UseElephentityTsquid(Routes(), shell ?? (_ => ValueTask.FromResult<string?>(Shell)), timeout: timeout);
                app.Run(context =>
                {
                    context.Response.StatusCode = StatusCodes.Status404NotFound;

                    return Task.CompletedTask;
                });
            }))
        .StartAsync(Token);

    private static HttpRequestMessage Navigate(string path, string viewer = "Alice")
    {
        var request = new HttpRequestMessage(HttpMethod.Get, path);

        request.Headers.Add("Accept", "text/html,application/xhtml+xml");
        request.Headers.Add("X-Viewer", viewer);

        return request;
    }

    [GeneratedRegex("id=\"tsquid-data\">(.*?)</script>", RegexOptions.Singleline)]
    private static partial Regex PayloadPattern();

    [Fact]
    public async Task AMatchedRouteIsServedWithItsDataForTheRequestingViewerAndNeverCached()
    {
        using var host = await Serve();
        using var client = host.GetTestClient();

        foreach (var viewer in new[] { "Alice", "Bob" })
        {
            var response = await client.SendAsync(Navigate("/me", viewer), Token);
            var body = await response.Content.ReadAsStringAsync(Token);

            Assert.Equal(HttpStatusCode.OK, response.StatusCode);
            Assert.StartsWith("text/html", response.Content.Headers.ContentType!.ToString(), StringComparison.Ordinal);
            Assert.True(response.Headers.CacheControl!.Private);
            Assert.True(response.Headers.CacheControl.NoStore);
            Assert.True(body.IndexOf("id=\"tsquid-data\"", StringComparison.Ordinal) < body.IndexOf("type=\"module\"", StringComparison.Ordinal));

            using var data = JsonDocument.Parse(PayloadPattern().Match(body).Groups[1].Value);
            var entry = data.RootElement.GetProperty("responses")[0];

            Assert.Equal("ProfileQuery", entry.GetProperty("operation").GetString());
            Assert.Equal(viewer, entry.GetProperty("response").GetProperty("data").GetProperty("viewer").GetProperty("name").GetString());
            Assert.False(entry.GetProperty("response").TryGetProperty("errors", out _));

            // Written for the real client to read (tests/client.mjs, run in CI).
            if (viewer == "Alice" && Environment.GetEnvironmentVariable("TSQUID_PAYLOAD_OUT") is { Length: > 0 } output)
            {
                await File.WriteAllTextAsync(output, PayloadPattern().Match(body).Groups[1].Value, Token);
            }
        }
    }

    [Fact]
    public async Task TheRouteIsMatchedOnTheRawTargetNotTheDecodedPath()
    {
        using var host = await Serve();
        using var client = host.GetTestClient();
        RequestGateway.Calls.Clear();

        using var request = Navigate("/named/a/b");
        request.Headers.Add("X-Raw-Target", "/named/a%2Fb");
        var response = await client.SendAsync(request, Token);

        Assert.Contains("id=\"tsquid-data\"", await response.Content.ReadAsStringAsync(Token), StringComparison.Ordinal);
        Assert.Equal("a/b", Assert.Single(RequestGateway.Calls)["name"]);
    }

    [Theory]
    [InlineData("/")]
    [InlineData("/somewhere/else")]
    public async Task ARouteWithNothingToInlineAndAUrlThatMatchesNothingServeThePlainShell(string path)
    {
        using var host = await Serve();
        using var client = host.GetTestClient();

        var response = await client.SendAsync(Navigate(path), Token);

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(Shell, await response.Content.ReadAsStringAsync(Token));
        Assert.Null(response.Headers.CacheControl);
    }

    [Theory]
    [InlineData("/graphql")]
    [InlineData("/assets/app.js")]
    public async Task TheGraphQlEndpointAndTheAssetsAreLeftAlone(string path)
    {
        using var host = await Serve();
        using var client = host.GetTestClient();

        Assert.Equal(HttpStatusCode.NotFound, (await client.SendAsync(Navigate(path), Token)).StatusCode);
    }

    [Fact]
    public async Task OnlyBrowserNavigationsAreAnswered()
    {
        using var host = await Serve();
        using var client = host.GetTestClient();
        using var json = new HttpRequestMessage(HttpMethod.Get, "/me");
        json.Headers.Add("Accept", "application/json");
        using var post = new HttpRequestMessage(HttpMethod.Post, "/me");
        post.Headers.Add("Accept", "text/html");

        Assert.Equal(HttpStatusCode.NotFound, (await client.SendAsync(json, Token)).StatusCode);
        Assert.Equal(HttpStatusCode.NotFound, (await client.SendAsync(post, Token)).StatusCode);
    }

    [Fact]
    public async Task AShellProviderThatHasNothingPassesTheRequestOn()
    {
        using var host = await Serve(_ => ValueTask.FromResult<string?>(null));
        using var client = host.GetTestClient();

        Assert.Equal(HttpStatusCode.NotFound, (await client.SendAsync(Navigate("/me"), Token)).StatusCode);
    }

    [Fact]
    public async Task ASlowQueryIsAbandonedAndThePlainShellServedWithoutCaching()
    {
        using var host = await Serve(timeout: TimeSpan.FromMilliseconds(150));
        using var client = host.GetTestClient();
        using var request = Navigate("/me");
        request.Headers.Add("X-Slow", "1");

        var started = DateTimeOffset.UtcNow;
        var response = await client.SendAsync(request, Token);

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(Shell, await response.Content.ReadAsStringAsync(Token));
        Assert.Null(response.Headers.CacheControl);
        Assert.True(DateTimeOffset.UtcNow - started < TimeSpan.FromSeconds(5));

        // The next, fast request still gets its data: one abandoned query is not a broken page.
        var fast = await (await client.SendAsync(Navigate("/me"), Token)).Content.ReadAsStringAsync(Token);

        Assert.Contains("id=\"tsquid-data\"", fast, StringComparison.Ordinal);
    }
}
