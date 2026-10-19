using Elephentity.Runtime.Storage;

namespace Elephentity.Runtime.Query;

/// <summary>
/// Turns a stored row into an entity.
/// </summary>
/// <remarks>
/// Generated per entity, because only generated code can call a generated constructor with exact types. Read
/// processors run here, on the way up. Hydration is a pure mapping (edges are lazy), so it is synchronous.
/// </remarks>
/// <typeparam name="T">The entity type.</typeparam>
public interface IHydrator<out T>
    where T : class
{
    /// <summary>Builds the entity for <paramref name="record" />.</summary>
    /// <param name="record">The stored row.</param>
    /// <param name="edges">Where the entity's edge accessors resolve from.</param>
    /// <returns>The entity.</returns>
    T Hydrate(Record record, IEdgeLoader edges);
}
