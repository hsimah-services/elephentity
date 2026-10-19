using Elephentity.Runtime.Query;

namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// A to-many edge exposed as a GraphQL connection.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Manifest\ConnectionEntry</c>. The delegate returns the lazy query the read model's accessor
/// returns, not a list: the connection pages it and counts it, and nothing is hydrated that the caller did not ask for.
/// </remarks>
/// <param name="Name">The GraphQL field name.</param>
/// <param name="FromType">The GraphQL type that has the field.</param>
/// <param name="ToType">The GraphQL type of the rows.</param>
/// <param name="Edge">The spec's name for the edge.</param>
/// <param name="Query">The query for the edge, from the read model that owns it; see <c>EntityQueries.AsObjects</c>.</param>
/// <param name="Description">The description, or <see langword="null" />.</param>
public sealed record ConnectionEntry(
    string Name,
    string FromType,
    string ToType,
    string Edge,
    Func<object, IEntityQuery<object>> Query,
    string? Description = null);
