namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// How a field's domain value becomes the type the manifest declared.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Manifest\FieldEncoding</c>, without <c>BackedEnum</c>: a C# enum is turned into its stored value by
/// the generated <c>&lt;Enum&gt;Wire</c> class, and PHP's accessor could not do that for itself where a C# delegate can (see
/// <c>docs/divergence.md</c>).
/// </remarks>
public enum FieldEncoding
{
    /// <summary>A value the delegate already returns in wire form; handed over untouched.</summary>
    Value,

    /// <summary>A row id, as the globally unique one the <c>Node</c> interface promises.</summary>
    GlobalId,

    /// <summary>A row id, as the string the <c>ID</c> scalar wants.</summary>
    Id,

    /// <summary>A <see cref="DateTimeOffset" />, as ISO 8601 with an offset (<c>2026-01-02T03:04:05+00:00</c>).</summary>
    Datetime,

    /// <summary>A <see cref="System.Text.Json.JsonElement" />, as the string of its JSON.</summary>
    Json,

    /// <summary>A declared value type, unwound to its backing primitive by its write processor.</summary>
    Processor,
}
