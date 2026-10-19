namespace Elephentity.Runtime.Verification;

/// <summary>
/// The result of verifying a single value.
/// </summary>
/// <remarks>
/// Verifiers return violations rather than throwing, so that a unit of work can run every
/// verifier up front and reject the whole commit with a complete list of problems instead of
/// surfacing them one round trip at a time.
/// </remarks>
public sealed record Verification
{
    private Verification(IReadOnlyList<Violation> violations)
    {
        Violations = violations;
    }

    /// <summary>Everything that was wrong, in the order it was found; empty when the value is fine.</summary>
    public IReadOnlyList<Violation> Violations { get; }

    /// <summary>Whether nothing was wrong.</summary>
    public bool IsOk => Violations.Count == 0;

    /// <summary>A value with nothing wrong.</summary>
    /// <returns>The passing result.</returns>
    public static Verification Ok() => new([]);

    /// <summary>A value with something wrong.</summary>
    /// <param name="violations">What was wrong; at least one.</param>
    /// <returns>The failing result.</returns>
    /// <exception cref="ArgumentException">There are no violations; use <see cref="Ok" />.</exception>
    public static Verification Failed(params Violation[] violations)
    {
        if (violations.Length == 0)
        {
            throw new ArgumentException(
                "Verification.Failed() requires at least one violation; use Verification.Ok().",
                nameof(violations));
        }

        return new Verification([.. violations]);
    }

    /// <summary>
    /// Combine two results, preserving violation order.
    /// </summary>
    /// <remarks>
    /// Both tiers of verification always run even when the first fails, so violations from the
    /// field verifier and the type processor aggregate into one response.
    /// </remarks>
    /// <param name="other">The result to add.</param>
    /// <returns>The combined result.</returns>
    public Verification Merge(Verification other)
    {
        if (IsOk)
        {
            return other;
        }

        return other.IsOk ? this : new Verification([.. Violations, .. other.Violations]);
    }
}
