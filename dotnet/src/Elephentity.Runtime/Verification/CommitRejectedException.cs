namespace Elephentity.Runtime.Verification;

/// <summary>
/// A commit refused because verification failed.
/// </summary>
/// <remarks>
/// Carries every violation from every field of every mutation in the unit of work. That completeness is the
/// reason verifiers return violations rather than throwing: a caller submitting fifteen fields learns about
/// all fifteen problems at once instead of one per round trip.
/// </remarks>
public sealed class CommitRejectedException : InvalidOperationException
{
    /// <summary>A rejection for <paramref name="violations" />.</summary>
    /// <param name="violations">Every violation found.</param>
    public CommitRejectedException(IReadOnlyList<FieldViolation> violations)
        : base(
            $"The commit was rejected by {violations.Count} violation(s):\n  "
            + string.Join("\n  ", violations.Select(violation => violation.Describe())))
    {
        Violations = violations;
    }

    /// <summary>Every violation, in the order it was found.</summary>
    public IReadOnlyList<FieldViolation> Violations { get; }

    /// <summary>The distinct paths that had a violation, in the order first found.</summary>
    /// <returns>The paths.</returns>
    public IReadOnlyList<string> Paths() => [.. Violations.Select(violation => violation.Path()).Distinct()];
}
