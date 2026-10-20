using System.Text.Json;

namespace Elephentity.Tsquid.Manifest;

/// <summary>
/// Reads the manifest tsquid's generator writes as JSON (<c>routes.manifest.json</c>).
/// </summary>
/// <remarks>
/// The C# builder emits the manifest as code; this is for an app that would rather read the committed JSON at deploy time, as the
/// SKILL suggests, and for the conformance vectors, which are JSON.
/// </remarks>
public static class ManifestJson
{
    /// <summary>Parses a manifest.</summary>
    /// <param name="json">The manifest's JSON.</param>
    /// <returns>The manifest.</returns>
    /// <exception cref="InvalidOperationException">The document is not a version 1 manifest.</exception>
    public static RouteManifest Parse(string json)
    {
        ArgumentNullException.ThrowIfNull(json);

        using var document = JsonDocument.Parse(json);

        return Read(document.RootElement);
    }

    /// <summary>Reads a manifest from a parsed document.</summary>
    /// <param name="root">The root object.</param>
    /// <returns>The manifest.</returns>
    /// <exception cref="InvalidOperationException">The document is not a version 1 manifest.</exception>
    public static RouteManifest Read(JsonElement root)
    {
        if (root.ValueKind != JsonValueKind.Object
            || !root.TryGetProperty("version", out var version)
            || !version.TryGetInt32(out var number)
            || !root.TryGetProperty("routes", out var routes)
            || routes.ValueKind != JsonValueKind.Array)
        {
            throw new InvalidOperationException("Unsupported or malformed tsquid manifest; expected version 1.");
        }

        return new RouteManifest([.. routes.EnumerateArray().Select(Route)], number);
    }

    private static TsquidRoute Route(JsonElement route)
    {
        var fields = new Dictionary<string, RouteField>();

        if (route.TryGetProperty("fields", out var declared))
        {
            foreach (var field in declared.EnumerateObject())
            {
                fields[field.Name] = Field(field.Value);
            }
        }

        return new TsquidRoute(
            route.GetProperty("name").GetString()!,
            route.GetProperty("path").GetString()!,
            route.GetProperty("ssr").GetBoolean(),
            fields,
            route.TryGetProperty("query", out var query) ? Query(query) : null);
    }

    private static RouteField Field(JsonElement field)
    {
        var kind = field.GetProperty("kind").GetString()!;
        var array = kind.EndsWith("[]", StringComparison.Ordinal);

        return new RouteField(
            (array ? kind[..^2] : kind) switch
            {
                "string" => FieldKind.Text,
                "int" => FieldKind.Whole,
                "bool" => FieldKind.Flag,
                _ => throw new InvalidOperationException($"Unknown tsquid field kind \"{kind}\"."),
            },
            array,
            field.GetProperty("optional").GetBoolean(),
            field.GetProperty("in").GetString() switch
            {
                "path" => FieldLocation.Path,
                "query" => FieldLocation.Query,
                var other => throw new InvalidOperationException($"Unknown tsquid field location \"{other}\"."),
            });
    }

    private static RouteQuery Query(JsonElement query)
    {
        var variables = new Dictionary<string, VariableBinding>();

        foreach (var variable in query.GetProperty("variables").EnumerateObject())
        {
            var hasDefault = variable.Value.TryGetProperty("default", out var value);

            variables[variable.Name] = new VariableBinding(
                variable.Value.TryGetProperty("input", out var input) ? input.GetString() : null,
                hasDefault,
                hasDefault ? Plain(value) : null);
        }

        return new RouteQuery(
            query.GetProperty("operation").GetString()!,
            query.GetProperty("id").GetString()!,
            query.GetProperty("text").GetString()!,
            variables);
    }

    // JSON as plain values, so a default can be handed to GraphQL and written back out without a serializer that needs reflection.
    private static object? Plain(JsonElement element) => element.ValueKind switch
    {
        JsonValueKind.String => element.GetString(),
        JsonValueKind.True => true,
        JsonValueKind.False => false,
        JsonValueKind.Number => element.TryGetInt64(out var number) ? number : element.GetDouble(),
        JsonValueKind.Array => element.EnumerateArray().Select(Plain).ToList(),
        JsonValueKind.Object => element.EnumerateObject().ToDictionary(property => property.Name, property => Plain(property.Value)),
        _ => null,
    };
}
