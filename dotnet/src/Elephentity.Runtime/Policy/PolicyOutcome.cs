namespace Elephentity.Runtime.Policy;

/// <summary>What one policy decided.</summary>
/// <remarks>
/// Policies run in order and the first outcome that is not <see cref="Skip" /> wins. At the gate
/// only <see cref="Allow" /> lets the operation through: <see cref="Deny" />, and a chain that
/// skipped everything and fell to the terminal rule's default of deny, both refuse.
/// </remarks>
public enum PolicyOutcome
{
    /// <summary>The operation is allowed.</summary>
    Allow,

    /// <summary>This policy has no opinion; the next one decides.</summary>
    Skip,

    /// <summary>The operation is refused.</summary>
    Deny,
}

/// <summary>The wire spelling of a <see cref="PolicyOutcome" />.</summary>
public static class PolicyOutcomeExtensions
{
    /// <summary>The spelling used in manifests: <c>allow</c>, <c>skip</c> or <c>deny</c>.</summary>
    /// <param name="outcome">The outcome.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this PolicyOutcome outcome) => outcome switch
    {
        PolicyOutcome.Allow => "allow",
        PolicyOutcome.Skip => "skip",
        PolicyOutcome.Deny => "deny",
        _ => throw new ArgumentOutOfRangeException(nameof(outcome)),
    };

    /// <summary>The outcome a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The outcome.</returns>
    /// <exception cref="ArgumentException">The name is not an outcome.</exception>
    public static PolicyOutcome FromWire(string value) => value switch
    {
        "allow" => PolicyOutcome.Allow,
        "skip" => PolicyOutcome.Skip,
        "deny" => PolicyOutcome.Deny,
        _ => throw new ArgumentException($"\"{value}\" is not a policy outcome.", nameof(value)),
    };
}
