namespace Elephentity.Runtime.Storage.Write;

/// <summary>
/// An ordered set of writes to apply together.
/// </summary>
/// <remarks>
/// Order is significant and is the unit of work's responsibility, not the adaptor's: with
/// server-generated ids a Post must be inserted before the Comments that reference it, so the
/// batch arrives already sorted by dependency.
/// </remarks>
public sealed record WriteBatch
{
    /// <summary>A batch of <paramref name="operations" />, in the order given.</summary>
    /// <param name="operations">The writes.</param>
    public WriteBatch(params IWriteOperation[] operations)
    {
        Operations = [.. operations];
    }

    /// <summary>The writes, in the order they apply.</summary>
    public IReadOnlyList<IWriteOperation> Operations { get; }

    /// <summary>How many writes the batch holds.</summary>
    public int Count => Operations.Count;

    /// <summary>Whether the batch holds nothing.</summary>
    public bool IsEmpty => Operations.Count == 0;
}
