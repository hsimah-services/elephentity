namespace Elephentity.Runtime.Capability;

/// <summary>
/// Something a storage adaptor may or may not be able to do.
/// </summary>
/// <remarks>
/// Backends differ genuinely, and flattening the port to a lowest common denominator would
/// make every adaptor as weak as the weakest. Adaptors declare instead, and the layers above
/// require what they need, so an unsupported combination fails at boot with a clear reason
/// rather than misbehaving at runtime.
/// </remarks>
public enum Capability
{
    /// <summary>Writes can be grouped so that all of them apply or none do.</summary>
    Transactions,

    /// <summary>Text can be searched by relevance rather than by exact or prefix match.</summary>
    FullTextSearch,

    /// <summary>Result sets can be counted and narrowed by facet without a second round trip.</summary>
    Faceting,

    /// <summary>Rows can be locked for update within a transaction.</summary>
    RowLocking,

    /// <summary>The backend enforces referential integrity itself.</summary>
    ForeignKeys,
}

/// <summary>The wire spelling of a <see cref="Capability" />.</summary>
public static class CapabilityExtensions
{
    /// <summary>The spelling used in manifests and messages: <c>fullTextSearch</c>, not <c>FullTextSearch</c>.</summary>
    /// <param name="capability">The capability.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this Capability capability) => capability switch
    {
        Capability.Transactions => "transactions",
        Capability.FullTextSearch => "fullTextSearch",
        Capability.Faceting => "faceting",
        Capability.RowLocking => "rowLocking",
        Capability.ForeignKeys => "foreignKeys",
        _ => throw new ArgumentOutOfRangeException(nameof(capability)),
    };

    /// <summary>The capability a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The capability.</returns>
    /// <exception cref="ArgumentException">The name is not a capability.</exception>
    public static Capability FromWire(string value) => value switch
    {
        "transactions" => Capability.Transactions,
        "fullTextSearch" => Capability.FullTextSearch,
        "faceting" => Capability.Faceting,
        "rowLocking" => Capability.RowLocking,
        "foreignKeys" => Capability.ForeignKeys,
        _ => throw new ArgumentException($"\"{value}\" is not a capability.", nameof(value)),
    };
}
