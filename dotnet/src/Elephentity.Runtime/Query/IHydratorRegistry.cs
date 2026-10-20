namespace Elephentity.Runtime.Query;

/// <summary>Finds the hydrator for an entity name.</summary>
public interface IHydratorRegistry
{
    /// <summary>Whether there is a hydrator for <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns><see langword="true" /> when there is.</returns>
    bool Has(string entity);

    /// <summary>The hydrator for <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The hydrator.</returns>
    IHydrator<object> HydratorFor(string entity);
}
