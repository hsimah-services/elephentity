namespace Elephentity.Runtime.SideEffect;

/// <summary>
/// When a side effect runs.
/// </summary>
/// <remarks>
/// <see cref="PreCommit" /> changes pending state before verification and persistence.
/// <see cref="PostCommit" /> runs after persistence; any writes it initiates are independent mutations.
/// </remarks>
public enum SideEffectPhase
{
    /// <summary>Before verification and persistence; may change the pending state.</summary>
    PreCommit,

    /// <summary>After persistence; the row exists and its id is real.</summary>
    PostCommit,
}

/// <summary>What a <see cref="SideEffectPhase" /> allows, and its wire spelling.</summary>
public static class SideEffectPhaseExtensions
{
    /// <summary>Whether a side effect in this phase may change the pending mutation.</summary>
    /// <param name="phase">The phase.</param>
    /// <returns><see langword="true" /> only before commit.</returns>
    public static bool AllowsMutation(this SideEffectPhase phase) => phase == SideEffectPhase.PreCommit;

    /// <summary>The spelling used in the spec: <c>preCommit</c> or <c>postCommit</c>.</summary>
    /// <param name="phase">The phase.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this SideEffectPhase phase) => phase switch
    {
        SideEffectPhase.PreCommit => "preCommit",
        SideEffectPhase.PostCommit => "postCommit",
        _ => throw new ArgumentOutOfRangeException(nameof(phase)),
    };

    /// <summary>The phase a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The phase.</returns>
    /// <exception cref="ArgumentException">The name is not a phase.</exception>
    public static SideEffectPhase FromWire(string value) => value switch
    {
        "preCommit" => SideEffectPhase.PreCommit,
        "postCommit" => SideEffectPhase.PostCommit,
        _ => throw new ArgumentException($"\"{value}\" is not a side-effect phase.", nameof(value)),
    };
}
