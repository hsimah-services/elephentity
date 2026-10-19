using System.Buffers;
using System.Globalization;
using System.Text;
using System.Text.Json;
using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage;

/// <summary>
/// A cursor that names the last row a page returned, by its sort values and its id.
/// </summary>
/// <remarks>
/// <para>
/// Keyset paging resumes <em>after</em> that row in the total order of the criteria (the sort
/// keys, then the id), so a page costs what it scans rather than everything before it, and a
/// row inserted or removed between two pages cannot make a reader see one twice or miss one.
/// It is how an entity with read policies is paged: the gate has to hydrate and filter rows, and
/// an offset would make it re-walk the rows it already discarded.
/// </para>
/// <para>
/// The token is <c>base64</c> of a JSON object <c>{"v":1,"k":[...],"id":...}</c>. <c>v</c> is the
/// format version, so an old or foreign token is recognised and ignored rather than misread;
/// <c>k</c> holds one value per sort term (before the final id term), as a string, a number, a
/// bool or null; <c>id</c> is the raw id, a number or a string.
/// </para>
/// </remarks>
public sealed record KeysetCursor : ICursor
{
    private const int Version = 1;

    /// <summary>A cursor after the row with these sort values and this id.</summary>
    /// <param name="sortValues">The row's value for each sort term, in order, excluding the id.</param>
    /// <param name="id">The row's id.</param>
    public KeysetCursor(IReadOnlyList<object?> sortValues, EntityId id)
    {
        ArgumentNullException.ThrowIfNull(sortValues);
        ArgumentNullException.ThrowIfNull(id);

        foreach (var value in sortValues)
        {
            if (value is not (null or string or bool or long or int or short or sbyte or byte or ushort or double or float))
            {
                throw new ArgumentException(
                    $"A keyset sort value must be a string, a number, a bool or null, not {value.GetType().Name}.",
                    nameof(sortValues));
            }
        }

        SortValues = sortValues;
        Id = id;
    }

    /// <summary>The last row's value for each sort term, in order, excluding the id.</summary>
    public IReadOnlyList<object?> SortValues { get; }

    /// <summary>The last row's id, the final term of the order.</summary>
    public EntityId Id { get; }

    /// <inheritdoc />
    public string Token => Convert.ToBase64String(Encode());

    /// <summary>
    /// The keyset a cursor holds, or <see langword="null" /> when it holds none.
    /// </summary>
    /// <remarks>
    /// <see langword="null" /> covers no cursor, a cursor of another kind, and a token that is
    /// unreadable or of another version. The caller restarts the list, as <see cref="OffsetCursor" />
    /// does, rather than failing a request someone is watching.
    /// </remarks>
    /// <param name="cursor">The cursor a client returned, if any.</param>
    /// <returns>The keyset, or <see langword="null" />.</returns>
    public static KeysetCursor? From(ICursor? cursor)
    {
        if (cursor is KeysetCursor keyset)
        {
            return keyset;
        }

        if (cursor is null)
        {
            return null;
        }

        try
        {
            var bytes = Convert.FromBase64String(cursor.Token);
            using var document = JsonDocument.Parse(bytes);

            return Read(document.RootElement);
        }
        catch (Exception exception) when (exception is FormatException or JsonException or ArgumentException)
        {
            return null;
        }
    }

    /// <inheritdoc />
    public override string ToString() => Token;

    private static KeysetCursor? Read(JsonElement root)
    {
        if (root.ValueKind != JsonValueKind.Object
            || !root.TryGetProperty("v", out var version)
            || version.ValueKind != JsonValueKind.Number
            || !version.TryGetInt32(out var number)
            || number != Version
            || !root.TryGetProperty("k", out var keys)
            || keys.ValueKind != JsonValueKind.Array
            || !root.TryGetProperty("id", out var id))
        {
            return null;
        }

        var values = new List<object?>();

        foreach (var key in keys.EnumerateArray())
        {
            if (!TryScalar(key, out var value))
            {
                return null;
            }

            values.Add(value);
        }

        return id.ValueKind switch
        {
            JsonValueKind.Number when id.TryGetInt64(out var integer) && integer >= 1 =>
                new KeysetCursor(values, EntityId.Of(integer)),
            JsonValueKind.String when !string.IsNullOrWhiteSpace(id.GetString()) =>
                new KeysetCursor(values, EntityId.Of(id.GetString()!)),
            _ => null,
        };
    }

    private static bool TryScalar(JsonElement element, out object? value)
    {
        switch (element.ValueKind)
        {
            case JsonValueKind.Null:
                value = null;
                return true;
            case JsonValueKind.True:
                value = true;
                return true;
            case JsonValueKind.False:
                value = false;
                return true;
            case JsonValueKind.String:
                value = element.GetString();
                return true;
            case JsonValueKind.Number when element.TryGetInt64(out var integer):
                value = integer;
                return true;
            case JsonValueKind.Number:
                value = element.GetDouble();
                return true;
            default:
                value = null;
                return false;
        }
    }

    private byte[] Encode()
    {
        var buffer = new ArrayBufferWriter<byte>();

        using (var writer = new Utf8JsonWriter(buffer))
        {
            writer.WriteStartObject();
            writer.WriteNumber("v", Version);
            writer.WriteStartArray("k");

            foreach (var value in SortValues)
            {
                WriteScalar(writer, value);
            }

            writer.WriteEndArray();

            switch (Id.Raw())
            {
                case long number:
                    writer.WriteNumber("id", number);
                    break;
                case string text:
                    writer.WriteString("id", text);
                    break;
            }

            writer.WriteEndObject();
        }

        return buffer.WrittenSpan.ToArray();
    }

    private static void WriteScalar(Utf8JsonWriter writer, object? value)
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
            case long or int or short or sbyte or byte or ushort:
                writer.WriteNumberValue(Convert.ToInt64(value, CultureInfo.InvariantCulture));
                break;
            case double or float:
                writer.WriteNumberValue(Convert.ToDouble(value, CultureInfo.InvariantCulture));
                break;
            default:
                // Unreachable: the constructor admits only the types handled above.
                throw new InvalidOperationException($"Cannot encode a {value.GetType().Name} in a keyset cursor.");
        }
    }
}
