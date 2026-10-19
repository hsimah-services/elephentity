using System.Net.Mime;
using GraphQL;
using GraphQL.Transport;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using Microsoft.Extensions.DependencyInjection;

namespace Elephentity.GraphQL.Http;

/// <summary>
/// Maps the GraphQL endpoint.
/// </summary>
public static class EndpointRouteBuilderExtensions
{
    /// <summary>Maps <c>POST</c> of a JSON GraphQL request to the schema registered by <c>AddElephentityGraphQL</c>.</summary>
    /// <param name="endpoints">The endpoints.</param>
    /// <param name="pattern">The route, usually <c>/graphql</c>.</param>
    /// <returns>The endpoint's convention builder, for authorization, CORS and the rest.</returns>
    /// <remarks>
    /// Only <c>POST</c> with <c>application/json</c>: a mutation must not be reachable by a link, and the HTTP concerns that are not
    /// GraphQL's (sessions, CSRF, authentication, a body-size limit) belong to ASP.NET Core: Kestrel refuses an oversized body, and
    /// a tighter limit for this route is <c>RequestSizeLimitAttribute</c> metadata on the builder this returns.
    /// </remarks>
    public static IEndpointConventionBuilder MapElephentityGraphQL(this IEndpointRouteBuilder endpoints, string pattern = "/graphql")
    {
        ArgumentNullException.ThrowIfNull(endpoints);

        return endpoints.MapPost(pattern, HandleAsync);
    }

    private static async Task HandleAsync(HttpContext http)
    {
        var executor = http.RequestServices.GetRequiredService<ElephentityGraphQLExecutor>();

        if (!http.Request.HasJsonContentType())
        {
            await Refuse(http, StatusCodes.Status415UnsupportedMediaType, "Send the request as application/json.").ConfigureAwait(false);

            return;
        }

        GraphQLRequest? request;

        try
        {
            request = await executor.Serializer.ReadAsync<GraphQLRequest>(http.Request.Body, http.RequestAborted).ConfigureAwait(false);
        }
        catch (System.Text.Json.JsonException)
        {
            request = null;
        }

        if (request is null || string.IsNullOrWhiteSpace(request.Query))
        {
            await Refuse(http, StatusCodes.Status400BadRequest, "The request must be a JSON object with a \"query\".").ConfigureAwait(false);

            return;
        }

        var result = await executor.ExecuteAsync(request, http.RequestServices, http.RequestAborted).ConfigureAwait(false);

        http.Response.ContentType = MediaTypeNames.Application.Json;
        await executor.Serializer.WriteAsync(http.Response.Body, result, http.RequestAborted).ConfigureAwait(false);
    }

    private static async Task Refuse(HttpContext http, int status, string message)
    {
        http.Response.StatusCode = status;
        http.Response.ContentType = MediaTypeNames.Application.Json;

        await using var writer = new System.Text.Json.Utf8JsonWriter(http.Response.Body);

        writer.WriteStartObject();
        writer.WriteStartArray("errors");
        writer.WriteStartObject();
        writer.WriteString("message", message);
        writer.WriteEndObject();
        writer.WriteEndArray();
        writer.WriteEndObject();
    }
}
