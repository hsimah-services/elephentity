namespace Elephentity.Runtime.Storage;

/// <summary>
/// One page of results, with the cursor needed to ask for the next.
/// </summary>
/// <typeparam name="T">What a page holds.</typeparam>
/// <param name="Items">The items on this page.</param>
/// <param name="Next">The cursor for the next page, or <see langword="null" /> on the last.</param>
public sealed record Page<T>(IReadOnlyList<T> Items, ICursor? Next = null)
{
    /// <summary>
    /// The most a bounded page may hold. A larger limit is capped to this.
    /// </summary>
    public const int MaxLimit = 1000;

    /// <summary>Whether there is a page after this one.</summary>
    public bool HasMore => Next is not null;

    /// <summary>Whether nothing is on this page.</summary>
    public bool IsEmpty => Items.Count == 0;
}

/// <summary>Builds <see cref="Page{T}" /> values without naming the item type twice.</summary>
public static class Page
{
    /// <summary>A page with nothing on it and nothing after.</summary>
    /// <typeparam name="T">What the page would hold.</typeparam>
    /// <returns>The empty page.</returns>
    public static Page<T> Empty<T>() => new([]);
}
