using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos.Manifest;

/// <summary>
/// An order the application sorts one entity by, so the container can index it.
/// </summary>
/// <remarks>
/// A Cosmos <c>ORDER BY</c> on more than one property fails unless a composite index covers it, and every
/// page of a list is ordered by its sort keys <em>then</em> the id. So every declared ordering becomes a composite
/// index of its terms followed by <c>id</c> ascending (unless the last term already is the id). The installer
/// creates them; an ordering the manifest does not declare is reported by the adaptor as exactly that, not as a
/// service error.
/// </remarks>
/// <param name="Entity">The entity.</param>
/// <param name="Terms">The sort terms, in order, by spec field name.</param>
public sealed record CosmosOrdering(string Entity, IReadOnlyList<Order> Terms);
