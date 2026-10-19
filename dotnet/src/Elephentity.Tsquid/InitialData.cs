using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using Elephentity.Tsquid.Manifest;
using Microsoft.Extensions.Logging;

namespace Elephentity.Tsquid;

/// <summary>
/// Runs a route's query and runs the HTML it came from through it.
/// </summary>
/// <param name="text">The query text, verbatim.</param>
/// <param name="variables">The variables, built from the URL.</param>
/// <param name="operation">The operation name.</param>
/// <param name="id">The persisted-query id, for an endpoint that takes ids instead of text.</param>
/// <param name="cancellationToken">Cancels the execution.</param>
/// <returns>The GraphQL response body as JSON (<c>data</c>, <c>errors</c>, <c>extensions</c>), or <see langword="null" /> on a transport failure.</returns>
public delegate Task<string?> ExecuteQuery(
    string text,
    IReadOnlyDictionary<string, object?> variables,
    string operation,
    string id,
    CancellationToken cancellationToken);

/// <summary>
/// Inlines the initial route's GraphQL response into the app's HTML.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\Tsquid\InitialData</c>. Best effort: whatever goes wrong, the shell is served as it was and the client fetches.
/// The payload and the escaping follow the SKILL (section 6): one <c>tsquid-data</c> script before the first script, with every less-than
/// sign written as a unicode escape, and U+2028/U+2029 escaped too.
/// </remarks>
public sealed partial class InitialData
{
    private static readonly Action<ILogger, string, string, Exception> Failed = LoggerMessage.Define<string, string>(
        LogLevel.Warning,
        new EventId(1, "InitialDataFailed"),
        "The initial data for route {Route} (operation {Operation}) could not be inlined; the shell is served and the client will fetch.");

    private static readonly Action<ILogger, string, string, Exception?> NoResponse = LoggerMessage.Define<string, string>(
        LogLevel.Debug,
        new EventId(2, "InitialDataNoResponse"),
        "The query for route {Route} (operation {Operation}) gave no GraphQL response; the shell is served.");

    private readonly RouteManifest manifest;
    private readonly ILogger? log;

    /// <summary>Creates the renderer.</summary>
    /// <param name="manifest">The app's routes.</param>
    /// <param name="log">Where a query that could not be inlined is reported; nothing is logged when omitted.</param>
    public InitialData(RouteManifest manifest, ILogger? log = null)
    {
        ArgumentNullException.ThrowIfNull(manifest);

        this.manifest = manifest;
        this.log = log;
    }

    /// <summary>The page for a URL: the shell, with the route's data inlined when there is some.</summary>
    /// <param name="url">The request target as it arrived (path and query, still percent-encoded).</param>
    /// <param name="html">The built <c>index.html</c>.</param>
    /// <param name="execute">Runs the route's query as the requesting viewer.</param>
    /// <param name="cancellationToken">Cancels the render.</param>
    /// <returns>The shell unchanged, or with the payload and <c>Cache-Control: private, no-store</c>.</returns>
    public async Task<HtmlResponse> RenderAsync(string url, string html, ExecuteQuery execute, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(url);
        ArgumentNullException.ThrowIfNull(html);
        ArgumentNullException.ThrowIfNull(execute);

        var plain = new HtmlResponse(html);

        if (manifest.Match(url) is not { Route: { Ssr: true, Query: { } query } } match)
        {
            return plain;
        }

        // Before any executable script, including a module placed in <head>.
        var insertion = Insertion().Match(html);

        if (!insertion.Success || Existing().IsMatch(html))
        {
            return plain;
        }

        string payload;

        try
        {
            var variables = RouteManifest.Variables(query, match.Input);
            var body = await execute(query.Text, variables, query.Operation, query.Id, cancellationToken).ConfigureAwait(false);

            if (body is null || !IsResponse(body))
            {
                if (log is not null)
                {
                    NoResponse(log, match.Route.Path, query.Operation, null);
                }

                return plain;
            }

            payload = Payload(query.Operation, variables, body);
        }
        catch (Exception exception)
        {
            // A failing query must never fail the page, and neither must a slow one: cancellation (the request going away, or the
            // middleware's timeout) is also just "inline nothing". It is said where someone looking will find it.
            if (log is not null)
            {
                Failed(log, match.Route.Path, query.Operation, exception);
            }

            return plain;
        }

        var script = "<script type=\"application/json\" id=\"tsquid-data\">" + payload + "</script>";

        return new HtmlResponse(
            html[..insertion.Index] + script + html[insertion.Index..],
            new Dictionary<string, string> { ["Cache-Control"] = "private, no-store" });
    }

    private static bool IsResponse(string body)
    {
        using var document = JsonDocument.Parse(body);

        return document.RootElement.ValueKind == JsonValueKind.Object
            && (document.RootElement.TryGetProperty("data", out _) || document.RootElement.TryGetProperty("errors", out _));
    }

    private static string Payload(string operation, Dictionary<string, object?> variables, string responseJson)
    {
        using var stream = new MemoryStream();

        using (var writer = new Utf8JsonWriter(stream))
        {
            writer.WriteStartObject();
            writer.WriteNumber("version", 1);
            writer.WriteStartArray("responses");
            writer.WriteStartObject();
            writer.WriteString("operation", operation);
            writer.WritePropertyName("variables");
            Write(writer, variables);
            writer.WritePropertyName("response");
            writer.WriteRawValue(responseJson);
            writer.WriteEndObject();
            writer.WriteEndArray();
            writer.WriteEndObject();
        }

        // The writer escapes what it writes. The response is embedded as it came, so escape the rest: a "<" can only be inside a string
        // here, where \u003c means the same thing, and so can the two line separators JavaScript treats as line ends.
        return Encoding.UTF8.GetString(stream.ToArray())
            .Replace("<", "\\u003c", StringComparison.Ordinal)
            .Replace("\u2028", "\\u2028", StringComparison.Ordinal)
            .Replace("\u2029", "\\u2029", StringComparison.Ordinal);
    }

    private static void Write(Utf8JsonWriter writer, object? value)
    {
        switch (value)
        {
            case null:
                writer.WriteNullValue();

                break;
            case string text:
                writer.WriteStringValue(text);

                break;
            case bool flag:
                writer.WriteBooleanValue(flag);

                break;
            case long number:
                writer.WriteNumberValue(number);

                break;
            case int number:
                writer.WriteNumberValue(number);

                break;
            case double number:
                writer.WriteNumberValue(number);

                break;
            case System.Collections.IDictionary map:
                writer.WriteStartObject();

                foreach (System.Collections.DictionaryEntry entry in map)
                {
                    writer.WritePropertyName((string)entry.Key);
                    Write(writer, entry.Value);
                }

                writer.WriteEndObject();

                break;
            case System.Collections.IEnumerable list:
                writer.WriteStartArray();

                foreach (var item in list)
                {
                    Write(writer, item);
                }

                writer.WriteEndArray();

                break;
            default:
                throw new InvalidOperationException($"Cannot write a {value.GetType().Name} into the payload.");
        }
    }

    [GeneratedRegex(@"<script\b|</head\s*>", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex Insertion();

    [GeneratedRegex(@"\bid\s*=\s*[""']tsquid-data[""']", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex Existing();
}
