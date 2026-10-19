namespace Elephentity.Tsquid;

/// <summary>
/// The HTML to serve, and the headers that must go with it.
/// </summary>
/// <param name="Body">The page: the shell, with the payload in it when there is one.</param>
/// <param name="Headers">Empty for the plain shell; <c>Cache-Control</c> when data for one viewer was inlined.</param>
public sealed record HtmlResponse(string Body, IReadOnlyDictionary<string, string>? Headers = null)
{
    /// <summary>The headers to send, if any.</summary>
    public IReadOnlyDictionary<string, string> Headers { get; } = Headers ?? new Dictionary<string, string>();
}
