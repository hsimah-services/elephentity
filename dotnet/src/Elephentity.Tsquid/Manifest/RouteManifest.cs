using System.Globalization;
using System.Text;

namespace Elephentity.Tsquid.Manifest;

/// <summary>
/// Every route of the app, and the rules that turn a URL into one of them and into the variables of its query.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\Tsquid\Manifest</c>, following the language-neutral <c>tsquid-server-integration</c> SKILL, whose
/// <c>vectors.json</c> the tests run. The matching and the variable rules are normative: they must agree with the client exactly, or
/// the payload is for a request the client never makes.
/// </remarks>
public sealed class RouteManifest
{
    private const long MaxSafeInteger = 9007199254740991;

    /// <summary>Creates the manifest.</summary>
    /// <param name="routes">Every route, in the order the generator listed them.</param>
    /// <param name="version">The manifest format; only <c>1</c> is understood.</param>
    /// <exception cref="InvalidOperationException">The format is not 1.</exception>
    public RouteManifest(IReadOnlyList<TsquidRoute> routes, int version = 1)
    {
        ArgumentNullException.ThrowIfNull(routes);

        if (version != 1)
        {
            throw new InvalidOperationException($"Unsupported tsquid manifest version {version}; expected version 1.");
        }

        Routes = routes;
    }

    /// <summary>Every route.</summary>
    public IReadOnlyList<TsquidRoute> Routes { get; }

    /// <summary>The route a URL selects, with its input; or <see langword="null" /> when none does.</summary>
    /// <param name="url">The request target as it arrived: path and query, still percent-encoded. A fragment is dropped.</param>
    /// <returns>The match, or <see langword="null" />: no candidate, a tie, or input that does not parse. Never another candidate.</returns>
    public RouteMatch? Match(string url)
    {
        ArgumentNullException.ThrowIfNull(url);

        var (path, queryString) = Split(url);
        var segments = Segments(path);
        TsquidRoute? best = null;
        var score = -1;
        var tied = false;

        foreach (var route in Routes)
        {
            var pattern = Segments(route.Path);

            if (pattern.Length != segments.Length || Static(pattern, segments) is not { } statics)
            {
                continue;
            }

            if (statics > score)
            {
                best = route;
                score = statics;
                tied = false;
            }
            else if (statics == score)
            {
                tied = true;
            }
        }

        // Equally good routes are the client's to resolve.
        return best is null || tied ? null : Input(best, segments, queryString);
    }

    /// <summary>The variables of a route's query for the input a URL gave it.</summary>
    /// <param name="query">The route's query.</param>
    /// <param name="input">The parsed input.</param>
    /// <returns>
    /// Each declared variable that its bound input field or its default gives a value; one with neither is omitted. A default that is
    /// JSON <c>null</c> is a default.
    /// </returns>
    public static Dictionary<string, object?> Variables(RouteQuery query, IReadOnlyDictionary<string, object?> input)
    {
        ArgumentNullException.ThrowIfNull(query);
        ArgumentNullException.ThrowIfNull(input);

        var variables = new Dictionary<string, object?>();

        foreach (var (name, binding) in query.Variables)
        {
            if (binding.Input is not null && input.TryGetValue(binding.Input, out var value))
            {
                variables[name] = value;
            }
            else if (binding.HasDefault)
            {
                variables[name] = binding.Default;
            }
        }

        return variables;
    }

    private static (string Path, string Query) Split(string url)
    {
        var fragment = url.IndexOf('#', StringComparison.Ordinal);

        if (fragment >= 0)
        {
            url = url[..fragment];
        }

        var question = url.IndexOf('?', StringComparison.Ordinal);

        return question < 0 ? (url, string.Empty) : (url[..question], url[(question + 1)..]);
    }

    // Trailing slashes go (the root stays "/"), then split: "/" is ["", ""], "/a" is ["", "a"].
    private static string[] Segments(string path)
    {
        var trimmed = path.TrimEnd('/');

        return (trimmed.Length == 0 ? "/" : trimmed).Split('/');
    }

    // How many static segments a pattern matched, or null when it cannot match at all.
    private static int? Static(string[] pattern, string[] segments)
    {
        var statics = 0;

        for (var index = 0; index < pattern.Length; index++)
        {
            if (pattern[index].StartsWith(':'))
            {
                if (segments[index].Length == 0)
                {
                    return null;
                }
            }
            else
            {
                if (PathValue(segments[index]) is not { } decoded || !string.Equals(decoded, pattern[index], StringComparison.OrdinalIgnoreCase))
                {
                    return null;
                }

                statics++;
            }
        }

        return statics;
    }

    private static RouteMatch? Input(TsquidRoute route, string[] segments, string queryString)
    {
        var path = new Dictionary<string, List<string>>();
        var pattern = Segments(route.Path);

        for (var index = 0; index < pattern.Length; index++)
        {
            if (pattern[index].StartsWith(':'))
            {
                if (PathValue(segments[index]) is not { } value)
                {
                    return null;
                }

                path[pattern[index][1..]] = [value];
            }
            else if (pattern[index] != segments[index])
            {
                // Static segments must equal the raw segments exactly: "/Articles" and "/articl%65s" select "/articles", then fail.
                return null;
            }
        }

        var query = new Dictionary<string, List<string>>();

        foreach (var pair in queryString.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var equals = pair.IndexOf('=', StringComparison.Ordinal);
            var key = QueryValue(equals < 0 ? pair : pair[..equals]);
            var value = QueryValue(equals < 0 ? string.Empty : pair[(equals + 1)..]);

            if (!query.TryGetValue(key, out var values))
            {
                query[key] = values = [];
            }

            values.Add(value);
        }

        var input = new Dictionary<string, object?>();

        foreach (var (name, field) in route.Fields)
        {
            var source = field.In == FieldLocation.Path ? path : query;

            if (!source.TryGetValue(name, out var values) || values.Count == 0)
            {
                if (!field.Optional)
                {
                    return null;
                }

                continue;
            }

            if (!field.Array && values.Count != 1)
            {
                return null;
            }

            var parsed = new List<object?>();

            foreach (var raw in values)
            {
                if (Value(field.Kind, raw) is not { } value)
                {
                    return null;
                }

                parsed.Add(value);
            }

            input[name] = field.Array ? parsed : parsed[0];
        }

        return new RouteMatch(route, input);
    }

    // decodeURIComponent: a % that is not followed by two hex digits fails, and so does text that is not UTF-8. "+" stays "+".
    private static string? PathValue(string value)
    {
        var bytes = new List<byte>(value.Length);

        for (var index = 0; index < value.Length; index++)
        {
            if (value[index] == '%')
            {
                if (!IsHex(value, index + 1) || !IsHex(value, index + 2))
                {
                    return null;
                }

                bytes.Add(Convert.ToByte(value.Substring(index + 1, 2), 16));
                index += 2;
            }
            else
            {
                Append(bytes, value, ref index);
            }
        }

        try
        {
            return new UTF8Encoding(false, true).GetString([.. bytes]);
        }
        catch (ArgumentException)
        {
            return null;
        }
    }

    // One character as UTF-8; a surrogate pair is one code point, so both halves go together.
    private static void Append(List<byte> bytes, string value, ref int index)
    {
        var length = char.IsHighSurrogate(value[index]) && index + 1 < value.Length && char.IsLowSurrogate(value[index + 1]) ? 2 : 1;

        bytes.AddRange(Encoding.UTF8.GetBytes(value.Substring(index, length)));
        index += length - 1;
    }

    private static bool IsHex(string value, int index) => index < value.Length && Uri.IsHexDigit(value[index]);

    // application/x-www-form-urlencoded, as URLSearchParams reads it: "+" is a space, a stray % is kept, and malformed UTF-8 becomes
    // U+FFFD rather than an error.
    private static string QueryValue(string value)
    {
        var bytes = new List<byte>(value.Length);

        for (var index = 0; index < value.Length; index++)
        {
            switch (value[index])
            {
                case '+':
                    bytes.Add((byte)' ');

                    break;
                case '%' when IsHex(value, index + 1) && IsHex(value, index + 2):
                    bytes.Add(Convert.ToByte(value.Substring(index + 1, 2), 16));
                    index += 2;

                    break;
                default:
                    Append(bytes, value, ref index);

                    break;
            }
        }

        return Encoding.UTF8.GetString([.. bytes]);
    }

    private static object? Value(FieldKind kind, string value) => kind switch
    {
        FieldKind.Text => value,
        FieldKind.Flag => value switch { "true" => true, "false" => false, _ => null },
        FieldKind.Whole => Integer(value),
        _ => null,
    };

    private static long? Integer(string value)
    {
        if (value.Length == 0 || !value.Skip(value[0] == '-' ? 1 : 0).All(char.IsAsciiDigit) || value is "-")
        {
            return null;
        }

        // A long this wide cannot be a safe integer either, so a failed parse is a refusal too.
        return long.TryParse(value, NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out var number) && number is >= -MaxSafeInteger and <= MaxSafeInteger
            ? number
            : null;
    }
}
