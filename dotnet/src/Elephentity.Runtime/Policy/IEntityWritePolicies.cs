namespace Elephentity.Runtime.Policy;

/// <summary>An entity's write policies, evaluated in order: the first that is not a skip decides.</summary>
/// <remarks>
/// Evaluation is asynchronous for the same reason as <see cref="IEntityReadPolicies" />: a policy can need to
/// consult something, and a policy that does not completes synchronously and allocates nothing.
/// </remarks>
public interface IEntityWritePolicies
{
    /// <summary>Decides whether <paramref name="viewer" /> may make the write described by <paramref name="context" />.</summary>
    /// <param name="entity">The entity as it was before the write; <see langword="null" /> on create.</param>
    /// <param name="context">What the write is.</param>
    /// <param name="viewer">Who is asking.</param>
    /// <param name="cancellationToken">Cancels the decision.</param>
    /// <returns>The decision; anything but an allow refuses.</returns>
    ValueTask<PolicyDecision> DecideAsync(
        object? entity,
        IWriteContext context,
        IViewer viewer,
        CancellationToken cancellationToken = default);

    /// <summary>Whether the entity has no write policies, so writes need no gate.</summary>
    /// <returns><see langword="true" /> when there are none.</returns>
    bool IsEmpty();
}
