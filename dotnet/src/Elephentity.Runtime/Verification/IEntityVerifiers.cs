using Elephentity.Runtime.Mutation;

namespace Elephentity.Runtime.Verification;

/// <summary>
/// The bridge between the runtime and one entity's exactly-typed field verifiers.
/// </summary>
/// <remarks>
/// A generated <c>PostPriceVerifier</c> takes a <c>Money</c> and a <c>PostMutationContext</c>, which cannot be
/// called through any shared interface. The generator therefore emits an implementation of this per entity,
/// which narrows the value and wraps the context before dispatching. Entities with no verified fields still get
/// one; it simply returns ok. Verification is asynchronous because a rule can need to consult something, as a
/// policy can; a rule that only reads the value completes synchronously and allocates nothing.
/// </remarks>
public interface IEntityVerifiers
{
    /// <summary>Verifies one field's value.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">The pending value.</param>
    /// <param name="context">The mutation the value is part of.</param>
    /// <param name="cancellationToken">Cancels the verification.</param>
    /// <returns>What is wrong with it, if anything.</returns>
    ValueTask<Verification> VerifyAsync(
        string field,
        object? value,
        IMutationContext context,
        CancellationToken cancellationToken = default);

    /// <summary>Fields this entity verifies, so the pipeline can skip the rest.</summary>
    /// <returns>The field names.</returns>
    IReadOnlyList<string> VerifiedFields();
}
