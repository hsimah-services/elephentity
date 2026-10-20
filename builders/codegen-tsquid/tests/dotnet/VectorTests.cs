using System.Text;
using System.Text.Json;
using Elephentity.Tsquid.Manifest;

namespace Golden.Tests;

/// <summary>
/// The emitted C# is the manifest tsquid wrote: it answers every case of the SKILL's <c>vectors.json</c> as the JSON does, and describes
/// the same routes field for field.
/// </summary>
public sealed class VectorTests
{
    private static readonly JsonElement Vectors = JsonDocument.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "vectors.json"))).RootElement.Clone();

    public static TheoryData<int> Urls { get; } = [.. Enumerable.Range(0, Vectors.GetProperty("urls").GetArrayLength())];

    private static RouteManifest Emitted => Golden.Vectors.TsquidManifestFactory.Create();

    [Theory]
    [MemberData(nameof(Urls))]
    public void EveryUrlSelectsTheRouteAndInputTheVectorSays(int index)
    {
        var vector = Vectors.GetProperty("urls")[index];
        var match = Emitted.Match(vector.GetProperty("url").GetString()!);
        var expected = vector.GetProperty("route");

        if (expected.ValueKind == JsonValueKind.Null)
        {
            Assert.Null(match);

            return;
        }

        Assert.NotNull(match);
        Assert.Equal(expected.GetString(), match.Route.Name);
        Assert.Equal(Canonical(Plain(vector.GetProperty("input"))), Canonical(match.Input));

        if (vector.TryGetProperty("variables", out var variables))
        {
            Assert.Equal(Canonical(Plain(variables)), Canonical(RouteManifest.Variables(match.Route.Query!, match.Input)));
        }
    }

    [Theory]
    [InlineData("vectors")]
    [InlineData("starter")]
    public void TheEmittedRoutesAreTheJsonRoutes(string name)
    {
        var json = name == "vectors"
            ? ManifestJson.Read(Vectors.GetProperty("manifest"))
            : ManifestJson.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "starter.manifest.json")));
        var emitted = name == "vectors" ? Golden.Vectors.TsquidManifestFactory.Create() : Golden.Starter.TsquidManifestFactory.Create();

        Assert.Equal(json.Routes.Count, emitted.Routes.Count);

        for (var index = 0; index < json.Routes.Count; index++)
        {
            Assert.Equal(Describe(json.Routes[index]), Describe(emitted.Routes[index]));
        }
    }

    private static string Describe(TsquidRoute route) =>
        $"{route.Name}|{route.Path}|{route.Ssr}|"
        + string.Join(",", route.Fields.OrderBy(f => f.Key, StringComparer.Ordinal).Select(f => $"{f.Key}:{f.Value}"))
        + "|"
        + (route.Query is null
            ? "-"
            : $"{route.Query.Operation}|{route.Query.Id}|{route.Query.Text}|"
                + string.Join(",", route.Query.Variables.OrderBy(v => v.Key, StringComparer.Ordinal).Select(v => $"{v.Key}:{v.Value.Input}:{v.Value.HasDefault}:{Canonical(v.Value.Default)}")));

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

    private static string Canonical(object? value)
    {
        var text = new StringBuilder();

        void Write(object? v)
        {
            switch (v)
            {
                case null:
                    text.Append("null");

                    break;
                case string s:
                    text.Append(JsonSerializer.Serialize(s));

                    break;
                case bool b:
                    text.Append(b ? "true" : "false");

                    break;
                case System.Collections.IDictionary map:
                    text.Append('{');

                    foreach (var key in map.Keys.Cast<string>().Order(StringComparer.Ordinal))
                    {
                        text.Append(JsonSerializer.Serialize(key)).Append(':');
                        Write(map[key]);
                        text.Append(',');
                    }

                    text.Append('}');

                    break;
                case System.Collections.IEnumerable list:
                    text.Append('[');

                    foreach (var item in list)
                    {
                        Write(item);
                        text.Append(',');
                    }

                    text.Append(']');

                    break;
                default:
                    text.Append(Convert.ToString(v, System.Globalization.CultureInfo.InvariantCulture));

                    break;
            }
        }

        Write(value);

        return text.ToString();
    }
}
