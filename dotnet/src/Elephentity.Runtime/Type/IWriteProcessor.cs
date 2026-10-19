using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Verification;

namespace Elephentity.Runtime.Type;

/// <summary>
/// Checks a domain value and turns it back into a stored primitive.
/// </summary>
/// <remarks>
/// <see cref="Verify" /> returns violations rather than throwing. Throwing would abort on the first
/// invalid field, so a caller submitting fifteen fields fixes one error per round trip; returning
/// lets the unit of work run every verifier up front and reject the whole commit with a complete
/// list. Null never arrives here, as with <see cref="IReadProcessor" />.
/// </remarks>
public interface IWriteProcessor
{
    /// <summary>Checks the domain value.</summary>
    /// <param name="value">The domain value; never null.</param>
    /// <param name="context">The mutation the value is part of.</param>
    /// <returns>What is wrong with it, if anything.</returns>
    Verification.Verification Verify(object value, IMutationContext context);

    /// <summary>Turns the domain value into the stored primitive. Called only after <see cref="Verify" /> has passed.</summary>
    /// <param name="value">The domain value; never null.</param>
    /// <returns>The primitive to store.</returns>
    object Write(object value);
}
