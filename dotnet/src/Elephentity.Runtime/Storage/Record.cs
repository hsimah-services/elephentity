using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage;

/// <summary>
/// One stored row, in primitive form.
/// </summary>
/// <remarks>
/// Values here are what the backend holds: the input side of a type's processors. Read
/// processors turn them into domain values on the way up; write processors turn domain values
/// back into these on the way down. The adaptor never sees a domain type.
/// </remarks>
/// <param name="Entity">The entity the row belongs to.</param>
/// <param name="Id">The row's id.</param>
/// <param name="Values">The row's values, keyed by field name: strings, numbers, bools or null.</param>
public sealed record Record(string Entity, EntityId Id, IReadOnlyDictionary<string, object?> Values)
{
    /// <summary>Whether the row holds a value, possibly null, for <paramref name="field" />.</summary>
    /// <param name="field">The field.</param>
    /// <returns><see langword="true" /> when the field is present.</returns>
    public bool Has(string field) => Values.ContainsKey(field);

    /// <summary>The value of <paramref name="field" />, or <see langword="null" /> when it is missing.</summary>
    /// <param name="field">The field.</param>
    /// <returns>The value.</returns>
    public object? Value(string field) => Values.TryGetValue(field, out var value) ? value : null;
}
