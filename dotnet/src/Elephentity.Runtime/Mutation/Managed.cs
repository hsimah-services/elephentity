namespace Elephentity.Runtime.Mutation;

/// <summary>
/// When the framework fills a field it owns.
/// </summary>
/// <remarks>
/// A mirror of the spec's own enum, for the same reason the PHP runtime mirrors it: the runtime must not
/// depend on the compiler, which is build-time and does not ship. Generated code names the case and the
/// two stay in step because one generator writes both ends.
/// </remarks>
public enum Managed
{
    /// <summary>Stamped when the row is inserted, and never again.</summary>
    Created,

    /// <summary>Stamped on every write, insert included.</summary>
    Modified,
}

/// <summary>The wire spelling of a <see cref="Managed" />.</summary>
public static class ManagedExtensions
{
    /// <summary>The spelling used in the spec: <c>created</c> or <c>modified</c>.</summary>
    /// <param name="managed">The policy.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this Managed managed) => managed switch
    {
        Managed.Created => "created",
        Managed.Modified => "modified",
        _ => throw new ArgumentOutOfRangeException(nameof(managed)),
    };

    /// <summary>The policy a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The policy.</returns>
    /// <exception cref="ArgumentException">The name is not a managed policy.</exception>
    public static Managed FromWire(string value) => value switch
    {
        "created" => Managed.Created,
        "modified" => Managed.Modified,
        _ => throw new ArgumentException($"\"{value}\" is not a managed-field policy.", nameof(value)),
    };
}
