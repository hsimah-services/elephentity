using System.Net.Mime;
using Elephentity.GraphQL.Http;
using Elephentity.Tsquid.Manifest;
using GraphQL;
using GraphQL.Transport;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.Features;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;

namespace Elephentity.Tsquid.Http;

/// <summary>
/// Serves a tsquid app's HTML shell, with the matched route's data inlined.
/// </summary>
public static class ApplicationBuilderExtensions
{
    private static readonly string[] DefaultSkipped = ["/graphql", "/assets/"];

    /// <summary>How long the page waits for its route's query before serving the shell for the client to fetch.</summary>
    public static readonly TimeSpan DefaultTimeout = TimeSpan.FromSeconds(1);

    /// <summary>
    /// Serves the single-page app's HTML for every browser navigation, inlining the initial route's GraphQL response where the URL matches
    /// a route that has one.
    /// </summary>
    /// <param name="app">The pipeline.</param>
    /// <param name="manifest">The app's routes.</param>
    /// <param name="indexHtml">Gives the built <c>index.html</c> (read at deploy time and cached, usually); <see langword="null" /> passes the request on.</param>
    /// <param name="skippedPaths">
    /// Path prefixes this never touches: <c>/graphql</c> and <c>/assets/</c> unless said otherwise. Put the route you gave
    /// <c>MapElephentityGraphQL</c> here if it is not <c>/graphql</c>.
    /// </param>
    /// <param name="timeout">
    /// How long the page waits for the query (<see cref="DefaultTimeout" />, one second, when omitted). A page that would otherwise fetch the
    /// data on load is better served without it than late; past the limit the plain shell is served, with no <c>Cache-Control</c>.
    /// </param>
    /// <returns><paramref name="app" />.</returns>
    /// <remarks>
    /// Only <c>GET</c> requests that accept <c>text/html</c> are answered. The route's query runs through the executor that
    /// <c>AddElephentityGraphQL</c> registered, with the request's own services, so the viewer is the one whose page this is.
    /// Whenever data is inlined the response is <c>Cache-Control: private, no-store</c>; with nothing to inline (or a query that failed or
    /// timed out) the shell is served as it is, and the reason is logged.
    /// </remarks>
    public static IApplicationBuilder UseElephentityTsquid(
        this IApplicationBuilder app,
        RouteManifest manifest,
        Func<HttpContext, ValueTask<string?>> indexHtml,
        IReadOnlyList<string>? skippedPaths = null,
        TimeSpan? timeout = null)
    {
        ArgumentNullException.ThrowIfNull(app);
        ArgumentNullException.ThrowIfNull(manifest);
        ArgumentNullException.ThrowIfNull(indexHtml);

        var initial = new InitialData(manifest, app.ApplicationServices.GetService<ILoggerFactory>()?.CreateLogger<InitialData>());
        var skipped = skippedPaths ?? DefaultSkipped;
        var limit = timeout ?? DefaultTimeout;

        return app.Use(async (http, next) =>
        {
            if (!IsNavigation(http.Request, skipped) || await indexHtml(http).ConfigureAwait(false) is not { } html)
            {
                await next(http).ConfigureAwait(false);

                return;
            }

            // The raw target, still percent-encoded: the matching rules read the URL as the client wrote it, and Request.Path has
            // already decoded it.
            var target = http.Features.Get<IHttpRequestFeature>()?.RawTarget is { Length: > 0 } raw ? raw : http.Request.Path + http.Request.QueryString;
            using var timer = CancellationTokenSource.CreateLinkedTokenSource(http.RequestAborted);

            timer.CancelAfter(limit);

            var page = await initial
                .RenderAsync(target, html, (text, variables, operation, _, token) => Execute(http, text, variables, operation, token), timer.Token)
                .ConfigureAwait(false);

            foreach (var (name, value) in page.Headers)
            {
                http.Response.Headers[name] = value;
            }

            http.Response.ContentType = MediaTypeNames.Text.Html + "; charset=utf-8";
            await http.Response.WriteAsync(page.Body, http.RequestAborted).ConfigureAwait(false);
        });
    }

    private static bool IsNavigation(HttpRequest request, IReadOnlyList<string> skipped) =>
        HttpMethods.IsGet(request.Method)
        && request.Headers.Accept.Any(accept => accept is not null && accept.Contains("text/html", StringComparison.OrdinalIgnoreCase))
        && !skipped.Any(prefix => request.Path.StartsWithSegments(prefix.TrimEnd('/'), StringComparison.OrdinalIgnoreCase));

    private static async Task<string?> Execute(
        HttpContext http,
        string text,
        IReadOnlyDictionary<string, object?> variables,
        string operation,
        CancellationToken cancellationToken)
    {
        var executor = http.RequestServices.GetRequiredService<ElephentityGraphQLExecutor>();
        var request = new GraphQLRequest
        {
            Query = text,
            OperationName = operation,
            Variables = new Inputs(new Dictionary<string, object?>(variables)),
        };
        var result = await executor.ExecuteAsync(request, http.RequestServices, cancellationToken).ConfigureAwait(false);

        // A query that was cut off can come back as a result full of errors; that is not a response to inline.
        cancellationToken.ThrowIfCancellationRequested();

        return executor.Serializer.Serialize(result);
    }
}
