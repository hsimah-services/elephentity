using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Mutation;

/// <summary>
/// Where a mutator's pending changes accumulate until the unit of work commits.
/// </summary>
/// <remarks>
/// A mutator is a command buffer, not a live row: setters record intent, and nothing reaches storage
/// until the commit runs verification over the complete pending state. That is what allows every
/// violation to be reported at once.
/// </remarks>
public interface IMutationBuffer
{
    /// <summary>The row being mutated.</summary>
    IIdentifier Target { get; }

    /// <summary>Record a field's new value, in domain form. Processors run at commit.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">The new value.</param>
    void Assign(string field, object? value);

    /// <summary>Whether this mutation touches <paramref name="field" />.</summary>
    /// <param name="field">The field.</param>
    /// <returns><see langword="true" /> when it does.</returns>
    bool IsChanged(string field);

    /// <summary>The pending changes to an edge.</summary>
    /// <param name="edge">The edge.</param>
    /// <returns>The edge's buffer, created on first use.</returns>
    IEdgeMutation Edge(string edge);

    /// <summary>Only the fields this mutation touches, in domain form.</summary>
    /// <returns>The changes, keyed by field.</returns>
    IReadOnlyDictionary<string, object?> Changes();
}
