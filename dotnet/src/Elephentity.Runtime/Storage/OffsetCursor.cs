using System.Globalization;
using System.Text;

namespace Elephentity.Runtime.Storage;

/// <summary>
/// A cursor carrying a row offset.
/// </summary>
/// <remarks>
/// Offset paging, deliberately, and worth being honest about: if rows are inserted or removed
/// between two pages, a reader can see a row twice or miss one. For the table this exists to
/// serve (page 1, 2, 3 of a list someone is looking at) offset is the right trade. The cursor
/// stays opaque, so replacing it later changes nothing above the adaptor. The token is
/// <c>base64("offset:N")</c>, as in PHP.
/// </remarks>
public sealed record OffsetCursor : ICursor
{
    private const string Prefix = "offset:";

    /// <summary>A cursor at <paramref name="value" /> rows in.</summary>
    /// <param name="value">The row offset; not negative.</param>
    /// <exception cref="ArgumentOutOfRangeException">The offset is negative.</exception>
    public OffsetCursor(long value)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(value);

        Value = value;
    }

    /// <summary>How many rows to skip.</summary>
    public long Value { get; }

    /// <inheritdoc />
    public string Token =>
        Convert.ToBase64String(Encoding.UTF8.GetBytes(Prefix + Value.ToString(CultureInfo.InvariantCulture)));

    /// <summary>
    /// The offset a cursor holds: zero for no cursor, and for anything unreadable.
    /// </summary>
    /// <remarks>
    /// A cursor from an older format should restart the list rather than fail a request someone
    /// is watching.
    /// </remarks>
    /// <param name="cursor">The cursor a client returned, if any.</param>
    /// <returns>The offset.</returns>
    public static OffsetCursor From(ICursor? cursor)
    {
        if (cursor is OffsetCursor offset)
        {
            return offset;
        }

        if (cursor is null)
        {
            return new OffsetCursor(0);
        }

        Span<byte> buffer = stackalloc byte[256];

        if (!Convert.TryFromBase64String(cursor.Token, buffer, out var written))
        {
            return new OffsetCursor(0);
        }

        var decoded = Encoding.UTF8.GetString(buffer[..written]);

        if (!decoded.StartsWith(Prefix, StringComparison.Ordinal))
        {
            return new OffsetCursor(0);
        }

        var digits = decoded[Prefix.Length..];

        return digits.Length > 0
            && digits.All(char.IsAsciiDigit)
            && long.TryParse(digits, NumberStyles.None, CultureInfo.InvariantCulture, out var value)
                ? new OffsetCursor(value)
                : new OffsetCursor(0);
    }

    /// <inheritdoc />
    public override string ToString() => Token;
}
