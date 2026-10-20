using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Mutation;

/// <summary>
/// The state a verifier is allowed to reason about.
/// </summary>
/// <remarks>
/// <para>
/// Holds the entity as it was when the mutation started, plus the pending changes, so a verifier
/// can compare a value against the original or against another field: the cross-field rules a
/// per-value processor could not otherwise express.
/// </para>
/// <para>
/// Every verifier runs against the same final pending state rather than incrementally as setters
/// fire, so ordering does not matter and two fields can validate against each other symmetrically.
/// Values are domain-typed on both sides: the write processor has not run at verify time, and
/// originals come from the hydrated entity.
/// </para>
/// <para>
/// Access is stringly-typed because a shared processor such as Money is used by many entities and
/// cannot accept a generated per-entity context. Rules that want exact types belong in a field
/// verifier, whose generated interface names both the entity and the field's domain type.
/// </para>
/// </remarks>
public interface IMutationContext
{
    /// <summary>The entity being mutated.</summary>
    string Entity { get; }

    /// <summary>The actions requested in this mutation.</summary>
    IReadOnlyList<ActionCall> Actions { get; }

    /// <summary>The entity as it was when the mutation started; <see langword="null" /> on create.</summary>
    object? OriginalEntity { get; }

    /// <summary>
    /// The row's identity: pending through pre-commit side effects and verification, resolved before
    /// post-commit side effects. <see cref="IsCreate" /> remains true after resolution.
    /// </summary>
    IIdentifier Id { get; }

    /// <summary>True when there is no prior state, so every <see cref="Original" /> is null.</summary>
    bool IsCreate { get; }

    /// <summary>The value as it was when the mutation started; null on create.</summary>
    /// <param name="field">The field.</param>
    /// <returns>The original value.</returns>
    object? Original(string field);

    /// <summary>
    /// The value this mutation will write, falling back to the original when the field is untouched,
    /// so a verifier always sees what the row will actually hold.
    /// </summary>
    /// <param name="field">The field.</param>
    /// <returns>The pending value.</returns>
    object? Pending(string field);

    /// <summary>Whether this mutation touches <paramref name="field" />.</summary>
    /// <param name="field">The field.</param>
    /// <returns><see langword="true" /> when it does.</returns>
    bool IsChanged(string field);

    /// <summary>Only the fields this mutation touches, in domain form.</summary>
    /// <returns>The changes, keyed by field.</returns>
    IReadOnlyDictionary<string, object?> Changes();

    /// <summary>
    /// What this mutation will attach this edge to.
    /// </summary>
    /// <remarks>
    /// This is the write side's own bookkeeping, not the row's true final state: there is no
    /// original edge state to fall back to (nothing here loads what is currently attached), so on an
    /// update an edge this mutation never touches reads as empty even though the row may already
    /// hold something. On create that distinction does not exist, which is exactly the case a
    /// cross-edge rule such as "exactly one of these three is set" needs.
    /// </remarks>
    /// <param name="edge">The edge.</param>
    /// <returns>The identifiers it will be attached to.</returns>
    IReadOnlyList<IIdentifier> PendingEdge(string edge);

    /// <summary>
    /// Whether this mutation's buffer recorded anything for this edge: an add, a remove, or a
    /// wholesale replacement. False does not mean the edge is empty; it means this mutation is
    /// silent about it.
    /// </summary>
    /// <param name="edge">The edge.</param>
    /// <returns><see langword="true" /> when the mutation touches the edge.</returns>
    bool IsEdgeChanged(string edge);
}
