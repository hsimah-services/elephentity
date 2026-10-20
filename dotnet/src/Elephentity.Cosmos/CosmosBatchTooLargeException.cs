namespace Elephentity.Cosmos;

/// <summary>A write needs more operations than one Cosmos transactional batch can hold.</summary>
/// <remarks>
/// Thrown before anything is sent, so nothing was written. The limit (100 operations in a batch) is Cosmos's; a unit
/// of work this large has to be split into smaller commits, which means giving up the atomicity of the whole.
/// </remarks>
public sealed class CosmosBatchTooLargeException : InvalidOperationException
{
    /// <summary>The most operations one transactional batch can hold.</summary>
    public const int Limit = 100;

    /// <summary>An error for a write of <paramref name="operations" /> operations.</summary>
    /// <param name="operations">How many operations the write needed once folded.</param>
    public CosmosBatchTooLargeException(int operations)
        : base($"This write needs {operations} operations, and a Cosmos transactional batch holds at most {Limit}. Nothing was written. Commit fewer rows and links at once.")
    {
        Operations = operations;
    }

    /// <summary>How many operations the write needed.</summary>
    public int Operations { get; }
}
