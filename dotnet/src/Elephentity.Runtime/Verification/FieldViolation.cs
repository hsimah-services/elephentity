namespace Elephentity.Runtime.Verification;

/// <summary>
/// A violation once the unit of work has attached where it happened.
/// </summary>
/// <remarks>
/// Verifiers do not know their own field (a shared processor like Money is used by many), so the path is
/// added here, at the point that does know.
/// </remarks>
/// <param name="Entity">The entity.</param>
/// <param name="Field">The field.</param>
/// <param name="Violation">What was wrong.</param>
public sealed record FieldViolation(string Entity, string Field, Violation Violation)
{
    /// <summary>Where it happened: <c>Entity.field</c>.</summary>
    /// <returns>The path.</returns>
    public string Path() => $"{Entity}.{Field}";

    /// <summary>A line for people: the path, the message and the code.</summary>
    /// <returns>The description.</returns>
    public string Describe() => $"{Path()}: {Violation.Message} [{Violation.Code}]";
}
