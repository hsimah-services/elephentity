namespace Elephentity.Runtime.Testing;

/// <summary>An adaptor did something the storage port forbids, or failed to do something it promises.</summary>
public sealed class ConformanceFailureException : Exception
{
    /// <summary>A failure carrying every problem one case found.</summary>
    /// <param name="caseName">The case that failed.</param>
    /// <param name="failures">What it found, at least one.</param>
    public ConformanceFailureException(string caseName, IReadOnlyList<string> failures)
        : base($"{caseName}: {string.Join(" ", failures)}")
    {
        CaseName = caseName;
        Failures = failures;
    }

    /// <summary>The case that failed.</summary>
    public string CaseName { get; }

    /// <summary>What it found.</summary>
    public IReadOnlyList<string> Failures { get; }
}
