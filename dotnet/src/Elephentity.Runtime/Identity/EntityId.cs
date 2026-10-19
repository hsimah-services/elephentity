using System.Globalization;

namespace Elephentity.Runtime.Identity;

/// <summary>
/// The identity of a persisted row, opaque above the storage layer.
/// </summary>
/// <remarks>
/// The underlying value is either a positive integer (SQLite, memory) or a non-blank string
/// (Cosmos). Nothing outside the adaptor may do arithmetic on it or assume it is numeric: the
/// entity exposes this object and GraphQL exposes a string. Keeping it opaque is what makes a
/// move between the two a change to the adaptor and a migration, rather than a change to every
/// consumer. An integer and the string that spells it are different ids, as <c>===</c> makes
/// them in PHP.
/// </remarks>
public sealed record EntityId : IIdentifier
{
    private readonly long? number;
    private readonly string? text;

    private EntityId(long? number, string? text)
    {
        this.number = number;
        this.text = text;
    }

    /// <inheritdoc />
    public bool IsPersisted => true;

    /// <summary>The id of a row whose storage assigns integers.</summary>
    /// <param name="value">A positive integer.</param>
    /// <returns>The id.</returns>
    /// <exception cref="ArgumentException">The value is not positive.</exception>
    public static EntityId Of(long value)
    {
        if (value < 1)
        {
            throw new ArgumentException($"An entity id cannot be {value.ToString(CultureInfo.InvariantCulture)}.", nameof(value));
        }

        return new EntityId(value, null);
    }

    /// <summary>The id of a row whose storage assigns strings.</summary>
    /// <param name="value">A string that is not empty or white space.</param>
    /// <returns>The id.</returns>
    /// <exception cref="ArgumentException">The value is blank.</exception>
    public static EntityId Of(string value)
    {
        ArgumentNullException.ThrowIfNull(value);

        if (string.IsNullOrWhiteSpace(value))
        {
            throw new ArgumentException("An entity id cannot be an empty string.", nameof(value));
        }

        return new EntityId(null, value);
    }

    /// <summary>
    /// The raw stored value: a <see cref="long" /> or a <see cref="string" />.
    /// </summary>
    /// <remarks>For storage adaptors only: everything above them treats an id as opaque.</remarks>
    /// <returns>The value as stored.</returns>
    public object Raw() => number.HasValue ? number.Value : text!;

    /// <inheritdoc />
    public bool Equals(IIdentifier? other) => other is EntityId id && Equals(id);

    /// <inheritdoc />
    public override string ToString() => number.HasValue ? number.Value.ToString(CultureInfo.InvariantCulture) : text!;
}
