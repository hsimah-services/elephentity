namespace Elephentity.Runtime.Policy;

/// <summary>A policy refused the operation.</summary>
public sealed class AccessDeniedException : InvalidOperationException
{
    /// <summary>Refused <paramref name="entity" /> by <paramref name="policy" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="policy">The policy that refused, or <see langword="null" /> when it is not known.</param>
    /// <param name="reason">Why.</param>
    public AccessDeniedException(string entity, string? policy, string reason)
        : base($"{entity}: {reason} (policy {(policy is null ? "unknown" : $"\"{policy}\"")})")
    {
        Entity = entity;
        Policy = policy;
        Reason = reason;
    }

    /// <summary>The entity that was refused.</summary>
    public string Entity { get; }

    /// <summary>The policy that refused, or <see langword="null" /> when it is not known.</summary>
    public string? Policy { get; }

    /// <summary>Why it was refused.</summary>
    public string Reason { get; }
}
