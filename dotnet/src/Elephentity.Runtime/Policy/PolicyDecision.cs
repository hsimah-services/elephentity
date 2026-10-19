namespace Elephentity.Runtime.Policy;

/// <summary>A policy's decision, with the reason and the name of the policy that made it.</summary>
public sealed record PolicyDecision
{
    private PolicyDecision(PolicyOutcome outcome, string reason, string? policy)
    {
        Outcome = outcome;
        Reason = reason;
        Policy = policy;
    }

    /// <summary>What was decided.</summary>
    public PolicyOutcome Outcome { get; }

    /// <summary>Why, for people and logs.</summary>
    public string Reason { get; }

    /// <summary>The policy that decided, or <see langword="null" /> when it is not known.</summary>
    public string? Policy { get; }

    /// <summary>Let the operation through.</summary>
    /// <param name="reason">Why.</param>
    /// <returns>The decision.</returns>
    public static PolicyDecision Allow(string reason = "") => new(PolicyOutcome.Allow, reason, null);

    /// <summary>Have no opinion, so the next policy decides.</summary>
    /// <param name="reason">Why.</param>
    /// <returns>The decision.</returns>
    public static PolicyDecision Skip(string reason = "") => new(PolicyOutcome.Skip, reason, null);

    /// <summary>Refuse the operation.</summary>
    /// <param name="reason">Why.</param>
    /// <returns>The decision.</returns>
    public static PolicyDecision Deny(string reason) => new(PolicyOutcome.Deny, reason, null);

    /// <summary>This decision, attributed to the policy called <paramref name="name" />.</summary>
    /// <param name="name">The policy's name.</param>
    /// <returns>The attributed decision.</returns>
    public PolicyDecision WithPolicy(string name) => new(Outcome, Reason, name);
}
