using System.Text;
using Elephentity.Runtime.Identity;

namespace Elephentity.GraphQL.Relay;

/// <summary>
/// The opaque identifier a Node hands out, and the row id hiding inside it.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Relay\GlobalId</c>, with the same encoding so a client's cache survives a move between the two: the
/// base64 of <c>eleph:&lt;Type&gt;:&lt;id&gt;</c>. A row number is unique to a table and not to a schema, so the type name has to
/// travel with the id.
/// </remarks>
public sealed record GlobalId(string Type, string Id)
{
    private const string Loader = "eleph";
    private const char Delimiter = ':';

    /// <summary>Encodes a type and row id.</summary>
    /// <param name="type">The GraphQL type name.</param>
    /// <param name="id">The row id.</param>
    /// <returns>The global id a client holds.</returns>
    public static string Encode(string type, string id)
    {
        ArgumentNullException.ThrowIfNull(type);
        ArgumentNullException.ThrowIfNull(id);

        return Convert.ToBase64String(Encoding.UTF8.GetBytes(string.Join(Delimiter, Loader, type, id)));
    }

    /// <summary>Encodes a type and row id.</summary>
    /// <param name="type">The GraphQL type name.</param>
    /// <param name="id">The row id.</param>
    /// <returns>The global id a client holds.</returns>
    public static string Encode(string type, EntityId id) => Encode(type, id.ToString());

    /// <summary>Back to its parts, or <see langword="null" /> when the string is not one of ours.</summary>
    /// <param name="encoded">What a client sent.</param>
    /// <returns>The parts, or <see langword="null" />.</returns>
    /// <remarks>
    /// Null rather than an exception because callers pair this with a fallback: a client holding a raw row id from before Node
    /// existed, or a spec field genuinely typed <c>id</c>, should keep working.
    /// </remarks>
    public static GlobalId? Decode(string encoded)
    {
        ArgumentNullException.ThrowIfNull(encoded);

        var buffer = new byte[encoded.Length];

        if (!Convert.TryFromBase64String(encoded, buffer, out var written))
        {
            return null;
        }

        string text;

        try
        {
            text = new UTF8Encoding(false, true).GetString(buffer, 0, written);
        }
        catch (ArgumentException)
        {
            return null;
        }

        var parts = text.Split(Delimiter, 3);

        return parts is [Loader, { Length: > 0 } type, { Length: > 0 } id] ? new GlobalId(type, id) : null;
    }

    /// <summary>The row id a client sent, whichever shape it is in.</summary>
    /// <param name="value">What the client sent.</param>
    /// <param name="expectedType">The GraphQL type the id must belong to, when it is a global id.</param>
    /// <returns>The id, or <see langword="null" /> when there is no usable id at all.</returns>
    /// <exception cref="GraphQLInputException">A global id of another type.</exception>
    public static EntityId? EntityIdOf(object? value, string? expectedType = null)
    {
        var raw = Raw(value, expectedType);

        try
        {
            return raw switch
            {
                long number => EntityId.Of(number),
                int number => EntityId.Of(number),
                string text => long.TryParse(text, System.Globalization.NumberStyles.None, System.Globalization.CultureInfo.InvariantCulture, out var parsed) && parsed >= 1
                    ? EntityId.Of(parsed)
                    : EntityId.Of(text),
                _ => null,
            };
        }
        catch (ArgumentException)
        {
            return null;
        }
    }

    /// <summary>A global id unwrapped, anything else left exactly as it arrived.</summary>
    /// <param name="value">What the client sent.</param>
    /// <param name="expectedType">The GraphQL type the id must belong to, when it is a global id.</param>
    /// <returns>The row id, or <paramref name="value" />.</returns>
    /// <exception cref="GraphQLInputException">A global id of another type.</exception>
    public static object? Raw(object? value, string? expectedType = null)
    {
        var decoded = value is string text ? Decode(text) : null;

        if (decoded is not null && expectedType is not null && decoded.Type != expectedType)
        {
            throw new GraphQLInputException($"Expected a {expectedType} ID, received {decoded.Type}.");
        }

        return decoded is null ? value : decoded.Id;
    }
}
