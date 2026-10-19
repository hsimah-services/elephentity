using System.Globalization;
using System.Text.Json;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.UnitOfWork;

namespace Elephentity.Runtime.Query;

/// <summary>
/// Turns stored primitives back into domain values.
/// </summary>
/// <remarks>
/// <para>
/// The mirror of <see cref="ValueEncoder" />, and the reason generated hydrators stay short: the coercion rules
/// live here and are tested once, rather than being emitted into every entity.
/// </para>
/// <para>
/// Every method fails loudly on the wrong shape. A column holding something the spec says it cannot is a
/// corrupted row or a missed migration, and continuing with a silently coerced value would bury the evidence.
/// A value that is <em>already</em> the domain type passes through, so the same methods decode input arriving
/// through the gateway without making a caller format a value for the framework to parse back.
/// </para>
/// </remarks>
public static class ValueDecoder
{
    /// <summary>A string.</summary>
    /// <param name="value">The stored value.</param>
    /// <param name="field">The field, for the message.</param>
    /// <returns>The string.</returns>
    public static string Text(object? value, string field) =>
        value as string ?? Reject<string>(value, "string", field);

    /// <summary>An integer.</summary>
    /// <remarks>Some drivers hand integers back as strings, so a numeric string is the expected shape, not an error.</remarks>
    /// <param name="value">The stored value.</param>
    /// <param name="field">The field, for the message.</param>
    /// <returns>The integer.</returns>
    public static long Whole(object? value, string field) => value switch
    {
        long number => number,
        int or short or sbyte or byte or ushort => Convert.ToInt64(value, CultureInfo.InvariantCulture),
        string text when IsInteger(text) && long.TryParse(text, NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out var parsed) => parsed,
        _ => Reject<long>(value, "int", field),
    };

    /// <summary>A floating-point number.</summary>
    /// <param name="value">The stored value.</param>
    /// <param name="field">The field, for the message.</param>
    /// <returns>The number.</returns>
    public static double Number(object? value, string field) => value switch
    {
        double number => number,
        float number => number,
        long or int or short or sbyte or byte or ushort => Convert.ToDouble(value, CultureInfo.InvariantCulture),
        string text when double.TryParse(text, NumberStyles.Float, CultureInfo.InvariantCulture, out var parsed) => parsed,
        _ => Reject<double>(value, "float", field),
    };

    /// <summary>A boolean; <c>1</c>, <c>0</c> and their string forms are accepted, as databases return them.</summary>
    /// <param name="value">The stored value.</param>
    /// <param name="field">The field, for the message.</param>
    /// <returns>The boolean.</returns>
    public static bool Bool(object? value, string field) => value switch
    {
        bool flag => flag,
        1L or 1 or "1" => true,
        0L or 0 or "0" or "" => false,
        _ => Reject<bool>(value, "bool", field),
    };

    /// <summary>A moment, always in UTC.</summary>
    /// <remarks>
    /// Stored values are ISO 8601 as the encoder writes them, or the older <c>Y-m-d H:i:s</c> with no offset,
    /// which was written in UTC; an explicit offset still wins.
    /// </remarks>
    /// <param name="value">The stored value.</param>
    /// <param name="field">The field, for the message.</param>
    /// <returns>The moment.</returns>
    public static DateTimeOffset DateTime(object? value, string field)
    {
        if (value is DateTimeOffset moment)
        {
            return moment;
        }

        if (value is System.DateTime dateTime)
        {
            return new DateTimeOffset(dateTime.ToUniversalTime());
        }

        var raw = Text(value, field);

        return DateTimeOffset.TryParse(
            raw,
            CultureInfo.InvariantCulture,
            DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
            out var parsed)
            ? parsed
            : throw new InvalidOperationException($"{field} holds \"{raw}\", which is not a date.");
    }

    /// <summary>An entity id.</summary>
    /// <param name="value">The stored value.</param>
    /// <param name="field">The field, for the message.</param>
    /// <returns>The id.</returns>
    public static EntityId Id(object? value, string field) => value switch
    {
        EntityId id => id,
        long number => EntityId.Of(number),
        int number => EntityId.Of(number),
        string text => EntityId.Of(text),
        _ => Reject<EntityId>(value, "id", field),
    };

    /// <summary>JSON data.</summary>
    /// <param name="value">The stored JSON text.</param>
    /// <param name="field">The field, for the message.</param>
    /// <returns>The parsed document's root, which stays valid after the document is disposed.</returns>
    public static JsonElement Json(object? value, string field)
    {
        var text = Text(value, field);

        try
        {
            using var document = JsonDocument.Parse(text);

            return document.RootElement.Clone();
        }
        catch (JsonException exception)
        {
            throw new InvalidOperationException($"{field} does not hold valid JSON: {exception.Message}", exception);
        }
    }

    /// <summary>A member of a generated enum.</summary>
    /// <typeparam name="T">The enum.</typeparam>
    /// <param name="value">The stored value, or an enum member already.</param>
    /// <param name="field">The field, for the message.</param>
    /// <param name="fromWire">The enum's <c>FromWire</c>, returning <see langword="null" /> for an unknown value.</param>
    /// <returns>The member.</returns>
    public static T Enum<T>(object? value, string field, Func<object, T?> fromWire)
        where T : struct, Enum
    {
        if (value is T member)
        {
            return member;
        }

        if (value is not (string or long or int))
        {
            return Reject<T>(value, typeof(T).Name, field);
        }

        // A value the enum no longer has is a migration that did not happen.
        return fromWire(value)
            ?? throw new InvalidOperationException($"{field} holds \"{value}\", which is not a member of {typeof(T).Name}.");
    }

    private static bool IsInteger(string text)
    {
        var digits = text.StartsWith('-') ? text[1..] : text;

        return digits.Length > 0 && digits.All(char.IsAsciiDigit);
    }

    private static T Reject<T>(object? value, string expected, string field) =>
        throw new InvalidOperationException(
            $"{field} holds a {value?.GetType().Name ?? "null"}, but the spec says {expected}.");
}
