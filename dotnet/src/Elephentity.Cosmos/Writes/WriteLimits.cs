namespace Elephentity.Cosmos.Writes;

/// <summary>Limits Cosmos puts on what one request can carry.</summary>
internal static class WriteLimits
{
    /// <summary>The most operations in a transactional batch.</summary>
    public const int BatchSize = CosmosBatchTooLargeException.Limit;

    /// <summary>The most operations in one patch.</summary>
    public const int PatchSize = 10;
}
