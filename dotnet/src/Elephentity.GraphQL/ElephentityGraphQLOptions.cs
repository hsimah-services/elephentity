namespace Elephentity.GraphQL;

/// <summary>
/// What an application may choose about its GraphQL endpoint.
/// </summary>
public sealed class ElephentityGraphQLOptions
{
    /// <summary>The page size when a client sends no <c>first</c>. Ten, as in the PHP package.</summary>
    /// <remarks>The reference application (hblake) configures 20.</remarks>
    public int DefaultPageSize { get; set; } = 10;

    /// <summary>The most a client can ask for in one page; <c>first: 1000000</c> is how a connection stops being a connection.</summary>
    public int MaxPageSize { get; set; } = 100;

    /// <summary>Whether <c>__schema</c> and <c>__type</c> answer. On by default, because the client's schema check needs it.</summary>
    public bool EnableIntrospection { get; set; } = true;

    /// <summary>The deepest selection a query may have, or <see langword="null" /> for no limit. The clog reference uses 12.</summary>
    public int? MaxDepth { get; set; }

    /// <summary>The most complex a query may be, or <see langword="null" /> for no limit. The clog reference uses 500.</summary>
    public int? MaxComplexity { get; set; }

    internal void Validate()
    {
        if (DefaultPageSize < 1)
        {
            throw new InvalidOperationException("ElephentityGraphQLOptions.DefaultPageSize must be at least 1.");
        }

        if (MaxPageSize < DefaultPageSize)
        {
            throw new InvalidOperationException("ElephentityGraphQLOptions.MaxPageSize must not be smaller than DefaultPageSize.");
        }

        if (MaxDepth is < 1 || MaxComplexity is < 1)
        {
            throw new InvalidOperationException("ElephentityGraphQLOptions limits must be positive.");
        }
    }
}
