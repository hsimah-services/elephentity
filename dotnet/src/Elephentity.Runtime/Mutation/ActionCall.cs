namespace Elephentity.Runtime.Mutation;

/// <summary>One requested action, retained throughout the mutation lifecycle.</summary>
/// <param name="Name">The action's name.</param>
/// <param name="Arguments">The arguments it was called with.</param>
public sealed record ActionCall(string Name, IReadOnlyDictionary<string, object?>? Arguments = null)
{
    /// <summary>The arguments it was called with; empty when it took none.</summary>
    public IReadOnlyDictionary<string, object?> Arguments { get; } = Arguments ?? new Dictionary<string, object?>();
}
