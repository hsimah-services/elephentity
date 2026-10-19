using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Gateway;

/// <summary>
/// A write named a row that is not there.
/// </summary>
/// <remarks>
/// An <see cref="InvalidOperationException" />, as it always was, so nothing that caught that stops catching this; the type is
/// for the layers (GraphQL, HTTP) that must tell "no such row" from every other failure without reading a message.
/// </remarks>
public sealed class EntityNotFoundException : InvalidOperationException
{
    /// <summary>Creates the exception.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="id">The id nothing has.</param>
    public EntityNotFoundException(string entity, EntityId id)
        : base($"There is no {entity} with id {id}.")
    {
        Entity = entity;
        Id = id;
    }

    /// <summary>The entity.</summary>
    public string Entity { get; }

    /// <summary>The id nothing has.</summary>
    public EntityId Id { get; }
}
