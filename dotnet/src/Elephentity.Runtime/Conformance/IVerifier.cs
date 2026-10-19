namespace Elephentity.Runtime.Conformance;

/// <summary>
/// What <c>eleph check</c> asks an integration: is the generated surface coherent?
/// </summary>
/// <remarks>
/// "Every field the API exposes resolves to a method that exists" is a question about an
/// integration's surface, not about GraphQL or any other integration specifically, and the
/// integration is the only thing that knows how far the question goes, so it ships the verifier
/// rather than answering to one core asks. A verifier is handed a type lookup, not the IR and not a
/// project directory: <c>eleph check</c> stays offline, and a verifier compares generated artefacts
/// against each other rather than rebuilding one from the spec to compare against the other, which
/// is the bug class unique to this gate.
/// </remarks>
public interface IVerifier
{
    /// <summary>Checks the surface.</summary>
    /// <param name="classFor">
    /// Entity name to fully-qualified type name, or the entity name itself when the tree never
    /// generated one.
    /// </param>
    /// <returns>Everything found, and everything that could not be checked.</returns>
    IReadOnlyList<Signal> Verify(Func<string, string> classFor);
}
