using Elephentity.Runtime.Conformance;

namespace Elephentity.Runtime.Testing;

/// <summary>Generated code and the application disagree.</summary>
public sealed class ContractCheckException : Exception
{
    /// <summary>A failure carrying every error the check found.</summary>
    /// <param name="errors">The error signals, at least one.</param>
    public ContractCheckException(IReadOnlyList<Signal> errors)
        : base($"{errors.Count} contract error(s):{Environment.NewLine}  {string.Join(Environment.NewLine + "  ", errors.Select(error => error.Message))}")
    {
        Errors = errors;
    }

    /// <summary>The error signals.</summary>
    public IReadOnlyList<Signal> Errors { get; }
}
