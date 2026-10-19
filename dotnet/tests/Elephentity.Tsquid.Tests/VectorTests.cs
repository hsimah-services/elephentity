using System.Text.Json;
using Elephentity.Tsquid.Manifest;

namespace Elephentity.Tsquid.Tests;

/// <summary>
/// The conformance vectors from the tsquid-server-integration SKILL: the tsquid repository tests them against the client, and every
/// server runs them too.
/// </summary>
public sealed class VectorTests
{
    private static readonly JsonElement Vectors = JsonDocument.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "Fixtures", "vectors.json"))).RootElement.Clone();

    private static RouteManifest Manifest => ManifestJson.Read(Vectors.GetProperty("manifest"));

    public static TheoryData<int> Urls { get; } = [.. Enumerable.Range(0, Vectors.GetProperty("urls").GetArrayLength())];

    public static TheoryData<int> Comparisons { get; } = [.. Enumerable.Range(0, Vectors.GetProperty("variables").GetArrayLength())];

    [Theory]
    [MemberData(nameof(Urls))]
    public void EveryUrlSelectsTheRouteAndInputTheVectorSays(int index)
    {
        var vector = Vectors.GetProperty("urls")[index];
        var url = vector.GetProperty("url").GetString()!;
        var match = Manifest.Match(url);
        var expected = vector.GetProperty("route");

        if (expected.ValueKind == JsonValueKind.Null)
        {
            Assert.Null(match);

            return;
        }

        Assert.NotNull(match);
        Assert.Equal(expected.GetString(), match.Route.Name);
        Assert.Equal(Json.Canonical(Json.Plain(vector.GetProperty("input"))), Json.Canonical(match.Input), StringComparer.Ordinal);

        if (vector.TryGetProperty("variables", out var variables))
        {
            Assert.Equal(
                Json.Canonical(Json.Plain(variables)),
                Json.Canonical(RouteManifest.Variables(match.Route.Query!, match.Input)),
                StringComparer.Ordinal);
        }
    }

    [Theory]
    [MemberData(nameof(Comparisons))]
    public void RelaysVariableComparisonIgnoresTopLevelNullsAndKeyOrderOnly(int index)
    {
        var vector = Vectors.GetProperty("variables")[index];
        var client = Json.Canonical(Json.Plain(vector.GetProperty("client")), dropTopLevelNulls: true);
        var server = Json.Canonical(Json.Plain(vector.GetProperty("server")), dropTopLevelNulls: true);

        Assert.Equal(vector.GetProperty("match").GetBoolean(), client == server);
    }
}
