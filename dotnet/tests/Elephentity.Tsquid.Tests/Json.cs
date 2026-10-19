using System.Text;
using System.Text.Json;

namespace Elephentity.Tsquid.Tests;

internal static class Json
{
    public static object? Plain(JsonElement element) => element.ValueKind switch
    {
        JsonValueKind.String => element.GetString(),
        JsonValueKind.True => true,
        JsonValueKind.False => false,
        JsonValueKind.Number => element.TryGetInt64(out var number) ? number : element.GetDouble(),
        JsonValueKind.Array => element.EnumerateArray().Select(Plain).ToList(),
        JsonValueKind.Object => element.EnumerateObject().ToDictionary(property => property.Name, property => Plain(property.Value)),
        _ => null,
    };

    /// <summary>A value as text with object keys sorted, so two values can be compared; top-level nulls are dropped when asked, as Relay's key does.</summary>
    public static string Canonical(object? value, bool dropTopLevelNulls = false)
    {
        var text = new StringBuilder();

        Write(text, value, dropTopLevelNulls);

        return text.ToString();
    }

    private static void Write(StringBuilder text, object? value, bool dropNulls)
    {
        switch (value)
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
            case long or int or double:
                text.Append(Convert.ToString(value, System.Globalization.CultureInfo.InvariantCulture));

                break;
            case System.Collections.IDictionary map:
                text.Append('{');
                var first = true;

                foreach (var key in map.Keys.Cast<string>().Order(StringComparer.Ordinal))
                {
                    if (dropNulls && map[key] is null)
                    {
                        continue;
                    }

                    text.Append(first ? string.Empty : ",").Append(JsonSerializer.Serialize(key)).Append(':');
                    Write(text, map[key], false);
                    first = false;
                }

                text.Append('}');

                break;
            case System.Collections.IEnumerable list:
                text.Append('[');
                var firstItem = true;

                foreach (var item in list)
                {
                    text.Append(firstItem ? string.Empty : ",");
                    Write(text, item, false);
                    firstItem = false;
                }

                text.Append(']');

                break;
            default:
                throw new InvalidOperationException(value.GetType().Name);
        }
    }
}
