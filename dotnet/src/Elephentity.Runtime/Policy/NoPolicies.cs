namespace Elephentity.Runtime.Policy;

/// <summary>The policies of an entity that declares none: everything is allowed, and no gate is needed.</summary>
public sealed class NoPolicies : IEntityReadPolicies, IEntityWritePolicies
{
    /// <inheritdoc />
    public ValueTask<PolicyDecision> DecideAsync(object entity, IViewer viewer, CancellationToken cancellationToken = default) =>
        ValueTask.FromResult(PolicyDecision.Allow());

    /// <inheritdoc />
    public ValueTask<PolicyDecision> DecideAsync(
        object? entity,
        IWriteContext context,
        IViewer viewer,
        CancellationToken cancellationToken = default) =>
        ValueTask.FromResult(PolicyDecision.Allow());

    /// <inheritdoc />
    public bool IsEmpty() => true;
}
