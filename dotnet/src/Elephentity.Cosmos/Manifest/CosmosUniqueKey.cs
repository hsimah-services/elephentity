namespace Elephentity.Cosmos.Manifest;

/// <summary>
/// A field whose value must be unique within its entity.
/// </summary>
/// <remarks>
/// Cosmos enforces uniqueness per logical partition with unique key paths, and a path cannot be scoped to one
/// entity type, so each entity's unique fields are written into synthetic properties (<c>_u0</c>, <c>_u1</c>, …)
/// as <c>"Entity|value"</c> and the container's unique key policy names those paths. The slot is the field's
/// position in <see cref="CosmosEntityLayout.UniqueKeys" />. Unique keys cannot be changed once a container exists.
/// </remarks>
/// <param name="Field">The spec field.</param>
/// <param name="CaseSensitive">
/// Whether <c>"Ab"</c> and <c>"ab"</c> are different values. Explicit in the manifest because the answer is part of the
/// schema, and unlike SQLite or MySQL there is no collation to inherit one from.
/// </param>
public sealed record CosmosUniqueKey(string Field, bool CaseSensitive = true);
