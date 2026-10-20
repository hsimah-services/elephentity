using System.Globalization;
using Elephentity.Cosmos.Manifest;

namespace Elephentity.Cosmos;

/// <summary>
/// The synthetic properties that carry uniqueness.
/// </summary>
/// <remarks>
/// <para>
/// A unique key path in Cosmos applies to every document in the logical partition, whatever its type, so
/// <c>/_u0</c> cannot mean "an item's barcode": a Post with the same text would collide with it. The value therefore
/// begins with the entity, <c>"Item|1234"</c>, and each entity's unique fields take slots 0, 1, … in order.
/// </para>
/// <para>
/// A document with no value in a slot still needs one, because an absent property counts as a value and two absent
/// ones collide. It gets a filler, <c>"Item|#id"</c>, which is unique because the id is. A real value is written
/// <c>"Item|=value"</c>; the character after the separator tells the two apart, so no stored value, however odd, can
/// be mistaken for a filler. A null value counts as no value, which is how a nullable unique column behaves in SQL:
/// any number of rows may have none.
/// </para>
/// </remarks>
public static class UniqueKeys
{
    /// <summary>The synthetic value of one unique field.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="key">The unique key.</param>
    /// <param name="value">The stored value; <see langword="null" /> has none.</param>
    /// <param name="id">The document's id, for the filler.</param>
    /// <returns>The slot's value.</returns>
    public static string Value(string entity, CosmosUniqueKey key, object? value, string id)
    {
        if (value is null)
        {
            return Filler(entity, id);
        }

        var text = Text(value);

        return $"{entity}|={(key.CaseSensitive ? text : text.ToUpperInvariant())}";
    }

    /// <summary>The value of a slot a document has no unique field for.</summary>
    /// <param name="entity">The entity, or the join type.</param>
    /// <param name="id">The document's id.</param>
    /// <returns>A value only this document has.</returns>
    public static string Filler(string entity, string id) => $"{entity}|#{id}";

    private static string Text(object value) => value switch
    {
        string text => text,
        bool flag => flag ? "true" : "false",
        long or int or short or sbyte or byte or ushort or uint => Convert.ToString(value, CultureInfo.InvariantCulture)!,
        double or float or decimal => Convert.ToString(value, CultureInfo.InvariantCulture)!,
        _ => throw new InvalidOperationException($"A {value.GetType().Name} cannot be a unique value."),
    };
}
