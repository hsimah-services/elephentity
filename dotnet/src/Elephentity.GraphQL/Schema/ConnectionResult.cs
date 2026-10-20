namespace Elephentity.GraphQL.Schema;

/// <summary>What a connection field resolves to: one page, and a total that is only counted if it is asked for.</summary>
internal sealed class ConnectionResult(
    IReadOnlyList<object> nodes,
    IReadOnlyList<EdgeResult> edges,
    bool hasNextPage,
    bool hasPreviousPage,
    string? startCursor,
    string? endCursor,
    Func<ValueTask<long>> total)
{
    public IReadOnlyList<object> Nodes { get; } = nodes;

    public IReadOnlyList<EdgeResult> Edges { get; } = edges;

    public PageInfoResult PageInfo { get; } = new(hasNextPage, hasPreviousPage, startCursor, endCursor);

    public ValueTask<long> TotalAsync() => total();
}

internal sealed record EdgeResult(string? Cursor, object Node);

internal sealed record PageInfoResult(bool HasNextPage, bool HasPreviousPage, string? StartCursor, string? EndCursor);
