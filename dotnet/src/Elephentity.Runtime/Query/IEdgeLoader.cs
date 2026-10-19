using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Query;

/// <summary>
/// Resolves an entity's edges, lazily and in batches.
/// </summary>
/// <remarks>
/// Generated entities hold one of these rather than their related entities, so nothing is fetched until an
/// accessor is called, and when fifty posts each ask for their comments the loader issues one query rather than
/// fifty. A to-many edge is a lazy query; a to-one edge has to be awaited, because it reads.
/// </remarks>
public interface IEdgeLoader
{
    /// <summary>The rows <paramref name="id" /> is linked to along a to-many edge.</summary>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="id">The row.</param>
    /// <param name="edge">The edge.</param>
    /// <returns>A lazy query over the targets.</returns>
    IEntityQuery<object> ToMany(string entity, EntityId id, string edge);

    /// <summary>The row <paramref name="id" /> is linked to along a to-one edge.</summary>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="id">The row.</param>
    /// <param name="edge">The edge.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The target, or <see langword="null" />.</returns>
    ValueTask<object?> ToOneAsync(string entity, EntityId id, string edge, CancellationToken cancellationToken = default);

    /// <summary>
    /// The same edge, read from the far end: rows of <paramref name="entity" /> whose <paramref name="edge" />
    /// points at <paramref name="id" />.
    /// </summary>
    /// <remarks>
    /// An inverse is not a second edge, so it is addressed by the edge that declares it. That is what lets
    /// <c>Item</c> answer "what is stocked here?" without <c>Item.yml</c> gaining an edge of its own, and what
    /// stops the two descriptions of one relationship ever disagreeing.
    /// </remarks>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="edge">The edge.</param>
    /// <param name="id">The row the edge points at.</param>
    /// <returns>A lazy query over the declaring rows.</returns>
    IEntityQuery<object> InverseToMany(string entity, string edge, EntityId id);

    /// <summary>The single row whose edge points at <paramref name="id" />.</summary>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="edge">The edge.</param>
    /// <param name="id">The row the edge points at.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The declaring row, or <see langword="null" />.</returns>
    ValueTask<object?> InverseToOneAsync(string entity, string edge, EntityId id, CancellationToken cancellationToken = default);
}
