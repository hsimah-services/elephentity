namespace Elephentity.Runtime.Verification;

/// <summary>
/// A single reason a value was rejected.
/// </summary>
/// <remarks>
/// A violation deliberately carries no field name. Type processors are shared across entities
/// and do not know which field they are attached to, so the unit of work attaches the field path
/// as it aggregates violations from every verifier.
/// </remarks>
/// <param name="Code">A stable, machine-readable identifier, e.g. <c>money.negative</c>.</param>
/// <param name="Message">A human-readable explanation. Never includes the field name.</param>
public sealed record Violation(string Code, string Message);
