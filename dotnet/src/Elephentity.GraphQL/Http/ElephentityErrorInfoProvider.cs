using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Verification;
using GraphQL;
using GraphQL.Execution;

namespace Elephentity.GraphQL.Http;

/// <summary>
/// Decides what a client is told about an error: a code it can switch on, and a message it can show.
/// </summary>
/// <remarks>
/// The mapping the clog reference does by hand: an access refusal is <c>FORBIDDEN</c>, a rejected commit is <c>BAD_USER_INPUT</c>
/// with every violation, a missing row is <c>NOT_FOUND</c>, something the client got wrong is <c>BAD_USER_INPUT</c>, and anything else
/// is <c>INTERNAL</c> with its message masked, because an exception message is for the log. Errors GraphQL itself raises (a syntax
/// error, an unknown field, a variable that does not coerce) keep their own message and code.
/// </remarks>
public sealed class ElephentityErrorInfoProvider : ErrorInfoProvider
{
    /// <inheritdoc />
    public override ErrorInfo GetInfo(ExecutionError executionError)
    {
        ArgumentNullException.ThrowIfNull(executionError);

        // Only an exception a resolver threw is ours to classify. GraphQL.NET's own input errors (a variable that does not coerce,
        // a syntax error) carry an InnerException too, and are the client's to read as they are.
        if (executionError is not UnhandledError)
        {
            return base.GetInfo(executionError);
        }

        var cause = executionError.InnerException;

        return cause switch
        {
            null => base.GetInfo(executionError),
            AccessDeniedException denied => Info("FORBIDDEN", denied.Reason),
            CommitRejectedException rejected => Rejected(rejected),
            EntityNotFoundException missing => Info("NOT_FOUND", missing.Message),
            GraphQLInputException input => Info("BAD_USER_INPUT", input.Message),
            _ => Info("INTERNAL", "Internal server error."),
        };
    }

    private static ErrorInfo Info(string code, string message, Dictionary<string, object?>? extra = null)
    {
        var extensions = new Dictionary<string, object?> { ["code"] = code };

        foreach (var (key, value) in extra ?? [])
        {
            extensions[key] = value;
        }

        return new ErrorInfo { Message = message, Extensions = extensions };
    }

    private static ErrorInfo Rejected(CommitRejectedException rejected) => Info(
        "BAD_USER_INPUT",
        $"The change was rejected by {rejected.Violations.Count} violation(s).",
        new Dictionary<string, object?>
        {
            ["violations"] = rejected.Violations
                .Select(violation => new Dictionary<string, object?>
                {
                    ["path"] = violation.Path(),
                    ["entity"] = violation.Entity,
                    ["field"] = violation.Field,
                    ["code"] = violation.Violation.Code,
                    ["message"] = violation.Violation.Message,
                })
                .ToList(),
        });
}
