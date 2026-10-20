namespace Elephentity.Runtime.Type;

/// <summary>
/// Turns a stored primitive into its domain type.
/// </summary>
/// <remarks>
/// The read half of a value type, mirroring the Entity/Mutator split at the type level. Null never
/// arrives here: a nullable field holding null short-circuits, so no processor needs to open with
/// the same null check. Values are untyped at this boundary, as in PHP, because the registry
/// resolves a processor by type name; a generated processor casts to the types it was written for.
/// </remarks>
public interface IReadProcessor
{
    /// <summary>Turns the stored value into the domain value.</summary>
    /// <param name="value">The stored primitive; never null.</param>
    /// <returns>The domain value.</returns>
    object Read(object value);
}
