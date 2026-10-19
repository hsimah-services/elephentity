namespace Elephentity.Runtime.Policy;

/// <summary>An entity's read policies, evaluated in order: the first that is not a skip decides.</summary>
/// <remarks>
/// Evaluation is asynchronous because a policy can need to consult something: "the viewer is a member of
/// this ticket's project" is a lookup, and on a document store it is an asynchronous one. A policy that
/// only reads fields of the entity completes synchronously and allocates nothing.
/// </remarks>
public interface IEntityReadPolicies
{
    /// <summary>Decides whether <paramref name="viewer" /> may read <paramref name="entity" />.</summary>
    /// <param name="entity">The hydrated entity.</param>
    /// <param name="viewer">Who is asking.</param>
    /// <param name="cancellationToken">Cancels the decision.</param>
    /// <returns>The decision; anything but an allow refuses.</returns>
    ValueTask<PolicyDecision> DecideAsync(object entity, IViewer viewer, CancellationToken cancellationToken = default);

    /// <summary>Whether the entity has no read policies, so reads need no gate.</summary>
    /// <returns><see langword="true" /> when there are none.</returns>
    bool IsEmpty();
}
