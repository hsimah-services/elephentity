namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// A GraphQL enum type.
/// </summary>
/// <remarks>Ported from <c>Eleph\GraphQL\Manifest\EnumTypeEntry</c>.</remarks>
/// <param name="Name">The type name.</param>
/// <param name="Values">GraphQL name to the stored value, in declaration order.</param>
public sealed record EnumTypeEntry(string Name, IReadOnlyDictionary<string, string> Values);
