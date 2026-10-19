using System.Text.Json;
using System.Text.RegularExpressions;
using Elephentity.Tsquid.Manifest;
using Microsoft.Extensions.Logging;

namespace Elephentity.Tsquid.Tests;

public sealed partial class InitialDataTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    private static readonly JsonElement Vectors = JsonDocument.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "Fixtures", "vectors.json"))).RootElement.Clone();

    private const string Html = "<html><head><script type=\"module\" src=\"/app.js\"></script></head><body></body></html>";

    private static RouteManifest Manifest => ManifestJson.Read(Vectors.GetProperty("manifest"));

    private sealed class Recorder(string? body)
    {
        public List<(string Text, IReadOnlyDictionary<string, object?> Variables, string Operation, string Id)> Calls { get; } = [];

        public Task<string?> Execute(string text, IReadOnlyDictionary<string, object?> variables, string operation, string id, CancellationToken token)
        {
            Calls.Add((text, variables, operation, id));

            return Task.FromResult(body);
        }
    }

    private static string Payload(string html) => PayloadPattern().Match(html).Groups[1].Value;

    [GeneratedRegex("id=\"tsquid-data\">(.*?)</script>", RegexOptions.Singleline)]
    private static partial Regex PayloadPattern();

    [Fact]
    public async Task APayloadIsInlinedBeforeTheModuleWithPrivateCachingAndTheExactQuery()
    {
        const string response = "{\"data\":{\"article\":{\"id\":\"7\",\"title\":\"</script><script>alert(1)</script>\"}},\"extensions\":{\"trace\":\"ok\"}}";
        var recorder = new Recorder(response);

        var page = await new InitialData(Manifest).RenderAsync("/articles/7", Html, recorder.Execute, Token);

        Assert.Equal("private, no-store", Assert.Single(page.Headers).Value);
        Assert.True(page.Body.IndexOf("id=\"tsquid-data\"", StringComparison.Ordinal) < page.Body.IndexOf("type=\"module\"", StringComparison.Ordinal));
        Assert.DoesNotContain("<script>alert(1)", page.Body, StringComparison.Ordinal);

        using var data = JsonDocument.Parse(Payload(page.Body));
        var entry = data.RootElement.GetProperty("responses")[0];

        Assert.Equal(1, data.RootElement.GetProperty("version").GetInt32());
        Assert.Equal("ArticleQuery", entry.GetProperty("operation").GetString());
        Assert.Equal("7", entry.GetProperty("variables").GetProperty("id").GetString());
        Assert.Equal("</script><script>alert(1)</script>", entry.GetProperty("response").GetProperty("data").GetProperty("article").GetProperty("title").GetString());

        var call = Assert.Single(recorder.Calls);

        Assert.Equal("query ArticleQuery($id: ID!) { article(id: $id) { id } }", call.Text);
        Assert.Equal("a2", call.Id);
        Assert.Equal("ArticleQuery", call.Operation);
        Assert.Equal("7", Assert.Single(call.Variables).Value);
    }

    [Fact]
    public async Task TheLineSeparatorsAreEscapedToo()
    {
        var recorder = new Recorder("{\"data\":{\"article\":{\"id\":\"7\",\"title\":\"a\u2028b\u2029c\"}}}");

        var page = await new InitialData(Manifest).RenderAsync("/articles/7", Html, recorder.Execute, Token);

        Assert.DoesNotContain('\u2028', page.Body);
        Assert.DoesNotContain('\u2029', page.Body);
        Assert.Contains("\\u2028", page.Body, StringComparison.Ordinal);
        Assert.Equal("a\u2028b\u2029c", JsonDocument.Parse(Payload(page.Body)).RootElement.GetProperty("responses")[0].GetProperty("response").GetProperty("data").GetProperty("article").GetProperty("title").GetString());
    }

    [Theory]
    [InlineData("/")]
    [InlineData("/articles/new")]
    [InlineData("/missing/route")]
    [InlineData("/Articles")]
    [InlineData("/articles?page=1&page=2")]
    public async Task ARouteThatDoesNotQualifyServesTheShellAsItIsAndRunsNothing(string url)
    {
        var recorder = new Recorder("{\"data\":{}}");

        var page = await new InitialData(Manifest).RenderAsync(url, Html, recorder.Execute, Token);

        Assert.Equal(Html, page.Body);
        Assert.Empty(page.Headers);
        Assert.Empty(recorder.Calls);
    }

    [Fact]
    public async Task APageThatAlreadyHasThePayloadOrNowhereToPutItIsLeftAlone()
    {
        var recorder = new Recorder("{\"data\":{}}");
        var initial = new InitialData(Manifest);
        var rendered = await initial.RenderAsync("/articles/7", Html, recorder.Execute, Token);

        Assert.Equal(rendered.Body, (await initial.RenderAsync("/articles/7", rendered.Body, recorder.Execute, Token)).Body);
        Assert.Equal("<html></html>", (await initial.RenderAsync("/articles/7", "<html></html>", recorder.Execute, Token)).Body);
        Assert.Single(recorder.Calls);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("[]")]
    [InlineData("{}")]
    [InlineData("not json")]
    public async Task AnUnusableResultFallsBackToTheShell(string? body)
    {
        var page = await new InitialData(Manifest).RenderAsync("/articles/7", Html, new Recorder(body).Execute, Token);

        Assert.Equal(Html, page.Body);
        Assert.Empty(page.Headers);
    }

    [Fact]
    public async Task AFailingExecutorNeverFailsThePage()
    {
        var page = await new InitialData(Manifest).RenderAsync(
            "/articles/7",
            Html,
            (_, _, _, _, _) => throw new TimeoutException("slow"),
            Token);

        Assert.Equal(Html, page.Body);
    }

    [Fact]
    public async Task GraphQlErrorsAreInlinedAsTheyCame()
    {
        var page = await new InitialData(Manifest).RenderAsync("/articles/7", Html, new Recorder("{\"data\":null,\"errors\":[{\"message\":\"Denied\"}]}").Execute, Token);

        Assert.Contains("Denied", page.Body, StringComparison.Ordinal);
    }

    [Fact]
    public async Task NoVariablesIsAnEmptyObjectNotAnArray()
    {
        var starter = ManifestJson.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "Fixtures", "starter.manifest.json")));

        var page = await new InitialData(starter).RenderAsync("/me", Html, new Recorder("{\"data\":{\"viewer\":null}}").Execute, Token);

        Assert.Contains("\"variables\":{}", page.Body, StringComparison.Ordinal);
    }

    [Fact]
    public void TiedRoutesDoNotInline()
    {
        var route = Manifest.Routes[2];

        Assert.Null(new RouteManifest([route, route]).Match("/articles/7"));
    }

    [Theory]
    [InlineData("/articles/%FF")]
    [InlineData("/articles/%C0%AF")]
    [InlineData("/articles/%")]
    [InlineData("/articles?page=9007199254740992")]
    [InlineData("/articles?page=-9007199254740992")]
    [InlineData("/articles?page=1%0A")]
    [InlineData("/articles?page=-")]
    [InlineData("/articles?page=%2B1")]
    public void InputThatDoesNotParseSelectsNothing(string url) => Assert.Null(Manifest.Match(url));

    [Fact]
    public void TheEdgesOfTheIntegerRuleAndOfQueryDecoding()
    {
        Assert.Equal(9007199254740991L, Manifest.Match("/articles?page=9007199254740991")!.Input["page"]);
        Assert.Equal(0L, Manifest.Match("/articles?page=-0")!.Input["page"]);
        Assert.Equal(new List<object?> { "�" }, Manifest.Match("/articles?tags=%FF")!.Input["tags"]);
        Assert.Equal(new List<object?> { "\U0001F600" }, Manifest.Match("/articles?tags=%F0%9F%98%80")!.Input["tags"]);
        Assert.Equal(new List<object?> { "\U0001F600" }, Manifest.Match("/articles?tags=\U0001F600")!.Input["tags"]);
        Assert.Equal("a/b", Manifest.Match("/articles/a%2Fb")!.Input["id"]);
    }

    [Fact]
    public void CaseInsensitiveSelectionMustNotFallBackToADynamicRoute()
    {
        var routes = new List<TsquidRoute>
        {
            new("Cafe", "/café", true, new Dictionary<string, RouteField>()),
            Manifest.Routes[6],
        };

        Assert.Null(new RouteManifest(routes).Match("/CAFÉ"));
    }

    [Fact]
    public void NestedDefaultsAndNullDefaultsAreKeptAndAFragmentIsIgnored()
    {
        var query = new RouteQuery("Q", "id", "query Q { x }", new Dictionary<string, VariableBinding>
        {
            ["filter"] = new(null, true, new Dictionary<string, object?> { ["empty"] = new Dictionary<string, object?>(), ["nested"] = null }),
            ["nullable"] = new(null, true, null),
            ["absent"] = new(null, false, null),
        });

        var variables = RouteManifest.Variables(query, new Dictionary<string, object?>());

        Assert.True(variables.ContainsKey("nullable"));
        Assert.False(variables.ContainsKey("absent"));
        Assert.True(((Dictionary<string, object?>)variables["filter"]!).ContainsKey("nested"));
        Assert.Equal("Article", Manifest.Match("/articles/7#section")!.Route.Name);
    }

    [Fact]
    public void OnlyVersionOneIsUnderstood()
    {
        Assert.Throws<InvalidOperationException>(() => new RouteManifest([], 2));
        Assert.Throws<InvalidOperationException>(() => ManifestJson.Parse("{\"version\":2,\"routes\":[]}"));
        Assert.Throws<InvalidOperationException>(() => ManifestJson.Parse("{\"routes\":[]}"));
    }

    private sealed class Lines : ILogger
    {
        public List<(LogLevel Level, string Message, Exception? Exception)> Entries { get; } = [];

        public IDisposable? BeginScope<TState>(TState state)
            where TState : notnull => null;

        public bool IsEnabled(LogLevel logLevel) => true;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter) =>
            Entries.Add((logLevel, formatter(state, exception), exception));
    }

    [Fact]
    public async Task AQueryThatFailsIsLoggedAsAWarningWithTheRouteAndOperation()
    {
        var log = new Lines();

        var page = await new InitialData(Manifest, log).RenderAsync("/articles/7", Html, (_, _, _, _, _) => throw new InvalidOperationException("schema drifted"), Token);

        Assert.Equal(Html, page.Body);

        var (level, message, exception) = Assert.Single(log.Entries);

        Assert.Equal(LogLevel.Warning, level);
        Assert.Contains("/articles/:id", message, StringComparison.Ordinal);
        Assert.Contains("ArticleQuery", message, StringComparison.Ordinal);
        Assert.Equal("schema drifted", exception!.Message);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("[]")]
    public async Task AnAbsentOrUnusableResponseIsLoggedAtDebug(string? body)
    {
        var log = new Lines();

        await new InitialData(Manifest, log).RenderAsync("/articles/7", Html, new Recorder(body).Execute, Token);

        var (level, message, _) = Assert.Single(log.Entries);

        Assert.Equal(LogLevel.Debug, level);
        Assert.Contains("ArticleQuery", message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task NothingIsLoggedWhenNothingWentWrong()
    {
        var log = new Lines();

        await new InitialData(Manifest, log).RenderAsync("/articles/7", Html, new Recorder("{\"data\":{}}").Execute, Token);
        await new InitialData(Manifest, log).RenderAsync("/missing", Html, new Recorder("{\"data\":{}}").Execute, Token);

        Assert.Empty(log.Entries);
    }
}
