namespace Elephentity.Runtime.Storage;

/// <summary>
/// A cursor as a client returned it: a token nothing has read yet.
/// </summary>
/// <remarks>
/// An adaptor reads one with <see cref="OffsetCursor.From" /> or <see cref="KeysetCursor.From" />,
/// whichever it produced.
/// </remarks>
public sealed record Cursor : ICursor
{
    private Cursor(string token)
    {
        Token = token;
    }

    /// <inheritdoc />
    public string Token { get; }

    /// <summary>A cursor for a token a client sent back.</summary>
    /// <param name="token">The token, exactly as it was issued.</param>
    /// <returns>The cursor.</returns>
    /// <exception cref="ArgumentException">The token is empty.</exception>
    public static Cursor Of(string token)
    {
        ArgumentNullException.ThrowIfNull(token);

        if (token.Length == 0)
        {
            throw new ArgumentException("A cursor cannot be empty.", nameof(token));
        }

        return new Cursor(token);
    }

    /// <inheritdoc />
    public override string ToString() => Token;
}
