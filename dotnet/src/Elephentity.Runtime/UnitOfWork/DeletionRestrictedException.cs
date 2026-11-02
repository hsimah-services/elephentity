using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// A delete refused because another row still depends on the target through an edge that says <c>restrict</c>.
/// </summary>
/// <remarks>
/// Ported from <c>packages/runtime/src/UnitOfWork/DeletionRestricted.php</c>. It is the system working as the spec says, not a
/// fault, so the layers that face a client (GraphQL, HTTP) report it as the client's to resolve. It stays an
/// <see cref="InvalidOperationException" />, as the planner always threw, so nothing that caught that stops catching this. The
/// message names the dependents and nothing else, so it is safe to show.
/// </remarks>
public sealed class DeletionRestrictedException : InvalidOperationException
{
    /// <summary>Creates the exception.</summary>
    /// <param name="entity">The entity being deleted.</param>
    /// <param name="id">The row being deleted.</param>
    /// <param name="dependent">The entity whose rows still depend on it.</param>
    /// <param name="edge">The edge that says restrict.</param>
    /// <param name="remaining">How many dependents remain.</param>
    public DeletionRestrictedException(string entity, EntityId id, string dependent, string edge, long remaining)
        : base(
            $"Cannot delete {entity}#{id}: {remaining} {dependent} still depend{(remaining == 1 ? "s" : string.Empty)} "
            + $"on it through \"{edge}\". The edge says restrict.")
    {
        Entity = entity;
        Id = id;
        Dependent = dependent;
        Edge = edge;
        Remaining = remaining;
    }

    /// <summary>The entity being deleted.</summary>
    public string Entity { get; }

    /// <summary>The row being deleted.</summary>
    public EntityId Id { get; }

    /// <summary>The entity whose rows still depend on it.</summary>
    public string Dependent { get; }

    /// <summary>The edge that says restrict.</summary>
    public string Edge { get; }

    /// <summary>How many dependents remain.</summary>
    public long Remaining { get; }
}
