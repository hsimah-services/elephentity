namespace Elephentity.GraphQL;

/// <summary>
/// The client sent something the schema cannot act on, and says so; not a defect in the server.
/// </summary>
/// <remarks>
/// A wrong-type global id, a malformed cursor, backward pagination: reported with <c>extensions.code</c> <c>BAD_USER_INPUT</c> and
/// its message, where any other unhandled exception is masked.
/// </remarks>
public sealed class GraphQLInputException : Exception
{
    /// <summary>Creates the exception.</summary>
    /// <param name="message">What was wrong, for the client.</param>
    public GraphQLInputException(string message)
        : base(message)
    {
    }
}
