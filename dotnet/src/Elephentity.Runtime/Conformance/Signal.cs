namespace Elephentity.Runtime.Conformance;

/// <summary>One thing a verifier found, or one thing it could not check.</summary>
/// <param name="Severity">How seriously the verifier means it.</param>
/// <param name="Message">What it found.</param>
public sealed record Signal(Severity Severity, string Message);
